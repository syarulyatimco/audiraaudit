<?php

namespace Tests\Feature\Admin;

use App\Enums\FirmStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingService;
use Tests\TestCase;

class SuperadminTest extends TestCase
{
    private function superadmin(): User
    {
        return User::factory()->superadmin()->create();
    }

    public function test_firm_users_cannot_access_admin_area(): void
    {
        [, $admin] = $this->makeFirm();
        $this->actingAs($admin)->get('/admin')->assertForbidden();
        $this->actingAs($admin)->get('/admin/plans')->assertForbidden();
    }

    public function test_superadmin_cannot_access_firm_audit_data(): void
    {
        [, $partner] = $this->makeFirm();
        $client = $this->client($partner);
        $sa = $this->superadmin();

        $this->actingAs($sa)->get('/clients')->assertForbidden();
        $this->actingAs($sa)->get("/clients/{$client->uuid}")->assertForbidden();
        $this->actingAs($sa)->get('/engagements')->assertForbidden();
    }

    public function test_superadmin_dashboard_and_firm_pages_render(): void
    {
        [$firm] = $this->makeFirm();
        $sa = $this->superadmin();

        $this->actingAs($sa)->get('/admin')->assertOk()->assertSee($firm->name);
        $this->actingAs($sa)->get('/admin/firms')->assertOk()->assertSee($firm->name);
        $this->actingAs($sa)->get("/admin/firms/{$firm->uuid}")->assertOk();
    }

    public function test_superadmin_edits_plan_price_without_changing_existing_subscription(): void
    {
        [$firm] = $this->makeFirm(['price_per_user_sen' => 2000]);
        $plan = $firm->currentSubscription()->first()->plan;
        $sa = $this->superadmin();

        $this->actingAs($sa)->put("/admin/plans/{$plan->id}", [
            'code' => $plan->code, 'name' => 'Standard', 'price_rm' => '25.50', 'min_paid_users' => 3,
            'max_users' => '', 'ai_credits_per_user' => 100, 'sort_order' => 1, 'is_active' => '1',
        ])->assertRedirect('/admin/plans');

        $this->assertSame(2550, $plan->fresh()->price_per_user_sen);
        $this->assertSame(2000, $firm->currentSubscription()->first()->price_per_user_sen, 'Existing subscription keeps its snapshot price');
    }

    public function test_superadmin_can_create_plan_and_change_firm_plan(): void
    {
        [$firm] = $this->makeFirm();
        $sa = $this->superadmin();

        $this->actingAs($sa)->post('/admin/plans', [
            'code' => 'enterprise', 'name' => 'Enterprise', 'price_rm' => '45', 'min_paid_users' => 10,
            'max_users' => '', 'ai_credits_per_user' => 500, 'sort_order' => 4, 'is_active' => '1',
        ])->assertRedirect('/admin/plans');
        $enterprise = Plan::where('code', 'enterprise')->sole();

        $this->actingAs($sa)->put("/admin/firms/{$firm->uuid}/plan", ['plan_id' => $enterprise->id])->assertSessionHasNoErrors();

        $current = $firm->currentSubscription()->first();
        $this->assertSame($enterprise->id, $current->plan_id);
        $this->assertSame(4500, $current->price_per_user_sen);
        $this->assertSame(1, $firm->subscriptions()->where('status', SubscriptionStatus::Cancelled->value)->count());
    }

    public function test_superadmin_suspends_and_restores_firm(): void
    {
        [$firm, $admin] = $this->makeFirm();
        $sa = $this->superadmin();

        $this->actingAs($sa)->post("/admin/firms/{$firm->uuid}/suspend", ['reason' => 'Unpaid invoice'])->assertSessionHasNoErrors();
        $this->assertSame(FirmStatus::Suspended, $firm->fresh()->status);
        $this->actingAs($admin)->get('/')->assertForbidden();

        $this->actingAs($sa)->post("/admin/firms/{$firm->uuid}/reactivate", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->actingAs($admin->fresh())->get('/')->assertOk();
    }

    public function test_billing_applies_minimum_paid_users_and_excludes_client_users(): void
    {
        [$firm, $admin] = $this->makeFirm(['price_per_user_sen' => 1500, 'min_paid_users' => 3]);
        $billing = app(BillingService::class);

        $estimate = $billing->estimate($firm);
        $this->assertSame(1, $estimate['active_users']);
        $this->assertSame(3, $estimate['billable_users']);
        $this->assertSame(4500, $estimate['monthly_total_sen']);

        $this->staff($firm);
        $this->staff($firm);
        $this->staff($firm);
        $this->staff($firm, attrs: ['status' => 'deactivated']);
        $client = $this->client($admin);
        User::factory()->create(['firm_id' => $firm->id, 'type' => 'client', 'client_id' => $client->id, 'firm_role' => null]);

        $estimate = $billing->estimate($firm);
        $this->assertSame(4, $estimate['active_users']);
        $this->assertSame(6000, $estimate['monthly_total_sen']);
    }

    public function test_create_superadmin_command(): void
    {
        $this->artisan('audira:create-superadmin', ['email' => 'ops@audira.my', 'name' => 'Ops', '--password' => 'Sup3r!Secure#Pass'])
            ->assertSuccessful();
        $this->assertTrue(User::where('email', 'ops@audira.my')->sole()->isSuperadmin());

        $this->artisan('audira:create-superadmin', ['email' => 'weak@audira.my', 'name' => 'Weak', '--password' => 'weak'])->assertFailed();
    }
}
