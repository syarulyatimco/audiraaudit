<?php

namespace Tests\Feature\Users;

use App\Enums\FirmRole;
use App\Enums\UserStatus;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function test_admin_can_invite_and_invitee_can_join(): void
    {
        Notification::fake();
        [$firm, $admin] = $this->makeFirm();

        $response = $this->actingAs($admin)->post('/users/invitations', [
            'name' => 'Aisyah Rahman', 'email' => 'aisyah@example.my', 'firm_role' => 'senior',
        ])->assertSessionHasNoErrors();

        $link = $response->getSession()->get('invitation_link');
        $this->assertNotNull($link);
        Notification::assertSentOnDemand(StaffInvitation::class);
        $token = basename(parse_url($link, PHP_URL_PATH));

        // the plain token is never stored
        $this->assertFalse(Invitation::where('token_hash', $token)->exists());

        auth()->logout();
        $this->get("/invitations/{$token}")->assertOk()->assertSee($firm->name);
        $this->post("/invitations/{$token}", [
            'name' => 'Aisyah Rahman', 'password' => 'Aisyah!Pass123', 'password_confirmation' => 'Aisyah!Pass123',
        ])->assertRedirect(route('dashboard'));

        $user = User::where('email', 'aisyah@example.my')->firstOrFail();
        $this->assertSame($firm->id, $user->firm_id);
        $this->assertSame(FirmRole::Senior, $user->firm_role);
        $this->assertAuthenticatedAs($user);

        // token cannot be reused
        auth()->logout();
        $this->get("/invitations/{$token}")->assertNotFound();
    }

    public function test_expired_invitation_is_rejected(): void
    {
        [$firm, $admin] = $this->makeFirm();
        $token = 'expired-token-value';
        Invitation::create([
            'firm_id' => $firm->id, 'email' => 'late@example.my', 'name' => 'Late', 'firm_role' => FirmRole::Associate,
            'token_hash' => hash('sha256', $token), 'invited_by' => $admin->id, 'expires_at' => now()->subDay(),
        ]);

        $this->get("/invitations/{$token}")->assertNotFound();
    }

    public function test_non_admin_cannot_invite_or_change_roles(): void
    {
        [$firm] = $this->makeFirm();
        $manager = $this->staff($firm, FirmRole::Manager);
        $associate = $this->staff($firm);

        $this->actingAs($manager)->post('/users/invitations', ['name' => 'X', 'email' => 'x@example.my', 'firm_role' => 'partner'])->assertForbidden();
        $this->actingAs($manager)->put("/users/{$associate->uuid}/role", ['firm_role' => 'partner'])->assertForbidden();
        $this->actingAs($associate)->get('/users')->assertForbidden();
    }

    public function test_seat_limit_blocks_invitations(): void
    {
        [$firm, $admin] = $this->makeFirm(['max_users' => 2]);
        $this->staff($firm);

        $this->actingAs($admin)->post('/users/invitations', ['name' => 'X', 'email' => 'x@example.my', 'firm_role' => 'associate'])
            ->assertSessionHasErrors('email');
        $this->assertSame(0, Invitation::count());
    }

    public function test_admin_can_change_role_and_deactivate(): void
    {
        [$firm, $admin] = $this->makeFirm();
        $user = $this->staff($firm);

        $this->actingAs($admin)->put("/users/{$user->uuid}/role", ['firm_role' => 'manager', 'is_firm_admin' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(FirmRole::Manager, $user->fresh()->firm_role);

        $this->actingAs($admin)->post("/users/{$user->uuid}/deactivate")->assertSessionHasNoErrors();
        $this->assertSame(UserStatus::Deactivated, $user->fresh()->status);
    }

    public function test_last_admin_cannot_be_removed_or_self_deactivated(): void
    {
        [, $admin] = $this->makeFirm();

        $this->actingAs($admin)->put("/users/{$admin->uuid}/role", ['firm_role' => 'partner', 'is_firm_admin' => '0'])->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->is_firm_admin);
        $this->actingAs($admin)->post("/users/{$admin->uuid}/deactivate")->assertSessionHasErrors('user');
    }

    public function test_admin_cannot_manage_users_of_another_firm(): void
    {
        [, $adminA] = $this->makeFirm();
        [$firmB] = $this->makeFirm();
        $victim = $this->staff($firmB);

        $this->actingAs($adminA)->post("/users/{$victim->uuid}/deactivate")->assertNotFound();
        $this->assertSame(UserStatus::Active, $victim->fresh()->status);
    }
}
