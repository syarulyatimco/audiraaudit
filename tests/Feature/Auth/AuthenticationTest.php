<?php

namespace Tests\Feature\Auth;

use App\Enums\FirmRole;
use App\Enums\FirmStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ActivityLog;
use App\Models\Firm;
use App\Models\User;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Sign in');
    }

    public function test_user_can_log_in_and_is_logged(): void
    {
        [, $admin] = $this->makeFirm();

        $this->post('/login', ['email' => strtoupper($admin->email), 'password' => 'Password!2345'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);
        $this->assertTrue(ActivityLog::where('event', 'auth.login')->where('user_id', $admin->id)->exists());
    }

    public function test_wrong_password_is_rejected_with_generic_message(): void
    {
        [, $admin] = $this->makeFirm();
        $this->post('/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        [, $admin] = $this->makeFirm();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $admin->email, 'password' => 'wrong']);
        }
        $this->post('/login', ['email' => $admin->email, 'password' => 'Password!2345'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        [$firm] = $this->makeFirm();
        $user = User::factory()->deactivated()->create(['firm_id' => $firm->id]);
        $this->post('/login', ['email' => $user->email, 'password' => 'Password!2345'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_with_live_session_is_logged_out(): void
    {
        [$firm] = $this->makeFirm();
        $user = User::factory()->deactivated()->create(['firm_id' => $firm->id]);
        $this->actingAs($user)->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_firm_registration_creates_trial_firm_subscription_and_admin_partner(): void
    {
        $plan = $this->plan(['name' => 'Standard', 'price_per_user_sen' => 2000]);

        $this->post('/register', [
            'firm_name' => 'Tan & Partners PLT', 'registration_no' => 'LLP0012345-LGN', 'audit_firm_no' => 'AF 1234',
            'name' => 'Tan Mei Ling', 'email' => 'MeiLing@TanPartners.my',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd',
            'plan_id' => $plan->id, 'terms' => '1',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'meiling@tanpartners.my')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(FirmRole::Partner, $user->firm_role);
        $this->assertTrue($user->is_firm_admin);

        $firm = Firm::findOrFail($user->firm_id);
        $this->assertSame(FirmStatus::Trial, $firm->status);
        $sub = $firm->currentSubscription()->first();
        $this->assertSame(SubscriptionStatus::Trialing, $sub->status);
        $this->assertSame(2000, $sub->price_per_user_sen);

        $this->get('/')->assertOk()->assertSee('Tan &amp; Partners PLT', false);
    }

    public function test_registration_rejects_weak_password_and_duplicate_email(): void
    {
        [, $admin] = $this->makeFirm();
        $plan = $this->plan();

        $this->post('/register', [
            'firm_name' => 'X', 'name' => 'Y', 'email' => strtoupper($admin->email),
            'password' => 'short', 'password_confirmation' => 'short', 'plan_id' => $plan->id, 'terms' => '1',
        ])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_user_can_change_password(): void
    {
        [, $admin] = $this->makeFirm();
        $this->actingAs($admin)->put('/account/password', [
            'current_password' => 'Password!2345', 'password' => 'N3w!Passw0rd#', 'password_confirmation' => 'N3w!Passw0rd#',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('N3w!Passw0rd#', $admin->fresh()->password));
    }

    public function test_suspended_firm_users_are_blocked(): void
    {
        [$firm, $admin] = $this->makeFirm();
        $firm->update(['status' => FirmStatus::Suspended, 'suspended_reason' => 'Non-payment']);

        $this->actingAs($admin)->get('/')->assertForbidden()->assertSee('Workspace suspended');
    }
}
