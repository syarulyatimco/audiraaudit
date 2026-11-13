<?php

namespace Tests\Feature\Platform;

use App\Enums\FirmRole;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\SupportAccessGrant;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingAndSupportTest extends TestCase
{
    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_invoices_bill_the_plan_minimum_apply_tax_only_when_configured_and_freeze(): void
    {
        [$firm, $admin] = $this->makeFirm(['price_per_user_sen' => 9_900, 'min_paid_users' => 3]);
        $this->staff($firm); // 2 active users < minimum 3
        $sub = $firm->currentSubscription()->with('plan')->first();
        $svc = app(InvoiceService::class);

        $inv = $svc->issue($sub, now()->startOfDay(), now()->addMonth()->subDay()->startOfDay());
        $this->assertSame([29_700, 0, 29_700, null], [$inv->subtotal_sen, $inv->tax_sen, $inv->total_sen, $inv->tax_name], 'no tax position is assumed');
        $this->assertSame(3, $inv->lines[0]['quantity']);
        $this->assertStringContainsString('minimum 3 paid users; 2 active', $inv->lines[0]['description']);
        $this->assertMatchesRegularExpression('/^AUD-\d{4}-000001$/', $inv->number);
        $this->assertSame($inv->id, $svc->issue($sub, now()->startOfDay(), now()->addMonth()->subDay()->startOfDay())->id, 'one invoice per period');

        config(['audira.billing.tax' => ['name' => 'Service tax', 'rate' => 8.0]]);
        $next = $svc->issue($sub, now()->addMonth()->startOfDay(), now()->addMonths(2)->subDay()->startOfDay());
        $this->assertSame([2_376, 32_076], [$next->tax_sen, $next->total_sen]);
        $this->assertStringEndsWith('000002', $next->number);

        app(TenantContext::class)->runAsSystem(function () use ($inv) {
            try {
                DB::transaction(fn () => DB::table('invoices')->where('id', $inv->id)->update(['total_sen' => 1, 'subtotal_sen' => 1]));
                $this->fail('issued amounts must be frozen');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        });

        // The firm sees its own invoices only.
        $this->actingAs($admin)->get('/firm/billing')->assertOk()->assertSee($inv->number);
        $this->actingAs($admin)->get("/firm/invoices/{$inv->uuid}")->assertOk()->assertSee('How to pay')->assertDontSee('Service tax');
        $this->actingAs($admin)->get("/firm/invoices/{$next->uuid}")->assertOk()->assertSee('Service tax (8%)');
        [$other, $otherAdmin] = $this->makeFirm();
        $this->actingAs($otherAdmin)->get("/firm/invoices/{$inv->uuid}")->assertNotFound();
        $this->actingAs($this->staff($firm))->get("/firm/invoices/{$inv->uuid}")->assertForbidden();

        // Platform staff record payment or void.
        $sa = $this->superadmin();
        $this->actingAs($sa)->post("/admin/invoices/{$inv->uuid}/paid", [])->assertSessionHasErrors('payment_reference');
        $this->actingAs($sa)->post("/admin/invoices/{$inv->uuid}/paid", ['payment_reference' => 'MBB-778812'])->assertSessionHasNoErrors();
        $this->actingAs($sa)->post("/admin/invoices/{$inv->uuid}/void", ['void_reason' => 'x'])->assertSessionHasErrors('invoice');
        $this->actingAs($sa)->post("/admin/invoices/{$next->uuid}/void", ['void_reason' => 'Issued in error'])->assertSessionHasNoErrors();
        $this->actingAs($sa)->get('/admin/invoices')->assertOk()->assertSee($inv->number);
        $this->actingAs($sa)->get("/admin/invoices/{$inv->uuid}")->assertOk()->assertSee('MBB-778812');
        $this->actingAs($sa)->get('/admin')->assertOk()->assertSee('Collected this month');
        $this->actingAs($admin)->get('/admin/invoices')->assertForbidden();
    }

    public function test_billing_run_issues_rolls_period_and_flags_past_due_until_paid(): void
    {
        [$firm] = $this->makeFirm(['price_per_user_sen' => 5_000, 'min_paid_users' => 1]);
        $sub = $firm->currentSubscription()->first();
        $sub->forceFill(['current_period_start' => now()->subMonth(), 'current_period_end' => now()->subMinute()])->save();
        $svc = app(InvoiceService::class);

        $this->assertSame(['issued' => 1, 'past_due' => 0], $svc->run());
        $this->assertSame(['issued' => 0, 'past_due' => 0], $svc->run(), 'period rolled forward; nothing more due');
        $sub->refresh();
        $this->assertTrue($sub->current_period_end->isFuture());
        $inv = app(TenantContext::class)->runAsSystem(fn () => Invoice::sole());

        $later = Carbon::parse($inv->due_on)->addDays(config('audira.billing.grace_days') + 1);
        $this->assertSame(1, $svc->run($later)['past_due']);
        $this->assertSame('past_due', $sub->fresh()->status->value);
        Carbon::setTestNow($later);
        $svc->markPaid($inv, 'FPX-1', 'fpx', $this->superadmin());
        Carbon::setTestNow();
        $this->assertSame('active', $sub->fresh()->status->value);
        $this->artisan('audira:billing-run')->assertSuccessful();
    }

    public function test_break_glass_support_access_is_firm_granted_time_boxed_read_only_and_logged(): void
    {
        [$firm, $admin] = $this->makeFirm();
        $partner = $this->staff($firm, FirmRole::Partner);
        $sa = $this->superadmin();

        $this->actingAs($partner)->get('/firm/support')->assertForbidden();
        $this->actingAs($admin)->post('/firm/support', ['reason' => 'Help with TB import error', 'hours' => 100])->assertSessionHasErrors('hours');
        $this->actingAs($admin)->post('/firm/support', ['reason' => 'Help with TB import error', 'hours' => 4])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/firm/support', ['reason' => 'Another grant please', 'hours' => 4])->assertSessionHasErrors('reason');
        $grant = app(TenantContext::class)->runAsSystem(fn () => SupportAccessGrant::sole());

        $this->actingAs($sa)->get('/admin/support')->assertOk()->assertSee($firm->name);
        $this->actingAs($sa)->get("/admin/support/{$grant->uuid}")->assertOk()->assertSee('Read-only support view')->assertSee($admin->name);
        $this->assertSame(1, $grant->fresh()->views);
        $logged = app(TenantContext::class)->runAsSystem(fn () => ActivityLog::where('firm_id', $firm->id)->where('event', 'support.viewed')->count());
        $this->assertSame(1, $logged, "every view is in the firm's audit trail");
        $this->actingAs($admin)->get('/firm/support')->assertOk()->assertSee('Help with TB import error');

        $this->actingAs($admin)->post("/firm/support/{$grant->uuid}/revoke")->assertSessionHasNoErrors();
        $this->actingAs($sa)->get("/admin/support/{$grant->uuid}")->assertForbidden();

        // Expired grants are refused too, and grants cannot exceed 72 hours at database level.
        $this->actingAs($admin)->post('/firm/support', ['reason' => 'Second support session', 'hours' => 1])->assertSessionHasNoErrors();
        $g2 = app(TenantContext::class)->runAsSystem(fn () => SupportAccessGrant::whereNull('revoked_at')->sole());
        Carbon::setTestNow(now()->addHours(2));
        $this->actingAs($sa)->get("/admin/support/{$g2->uuid}")->assertForbidden();
        Carbon::setTestNow();
        $this->expectException(QueryException::class);
        app(TenantContext::class)->runAsSystem(fn () => DB::table('support_access_grants')->where('id', $g2->id)->update(['expires_at' => now()->addDays(10)]));
    }

    public function test_security_headers(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'none'", $this->get('/login')->headers->get('Content-Security-Policy'));
        [, $admin] = $this->makeFirm();
        $this->assertStringContainsString('no-store', $this->actingAs($admin)->get('/firm/billing')->headers->get('Cache-Control'));
    }
}
