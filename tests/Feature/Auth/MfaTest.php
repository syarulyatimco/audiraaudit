<?php

namespace Tests\Feature\Auth;

use App\Enums\FirmRole;
use App\Services\Auth\MfaService;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['audira.mfa.enforced' => true]);
    }

    private function enableFor($user): array
    {
        $secret = app(Google2FA::class)->generateSecretKey(32);
        $codes = app(MfaService::class)->enable($user, $secret, app(Google2FA::class)->getCurrentOtp($secret));
        // Simulate enrolment having happened in an earlier time window, so the current code is fresh.
        $user->forceFill(['mfa_last_timestep' => null])->save();

        return [$secret, $codes];
    }

    public function test_partner_without_mfa_is_sent_to_setup_but_associate_is_not(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $associate = $this->staff($firm);

        $this->actingAs($partner)->get('/')->assertRedirect(route('mfa.setup'));
        $this->actingAs($partner)->get('/account/mfa/setup')->assertOk()->assertSee('Manual key');
        $this->actingAs($associate)->get('/')->assertOk();
    }

    public function test_setup_enables_mfa_and_stores_secret_encrypted(): void
    {
        [, $partner] = $this->makeFirm();
        $this->actingAs($partner)->get('/account/mfa/setup');
        $secret = session('mfa.pending_secret');

        $this->actingAs($partner)->post('/account/mfa/enable', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($partner)->post('/account/mfa/enable', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('account.edit'))->assertSessionHas('recovery_codes');

        $partner->refresh();
        $this->assertTrue($partner->hasMfaEnabled());
        $raw = DB::table('users')->where('id', $partner->id)->value('mfa_secret');
        $this->assertNotSame($secret, $raw, 'Secret must be encrypted at rest');
        $this->actingAs($partner)->get('/')->assertOk(); // verified in this session
    }

    public function test_login_requires_challenge_and_rejects_replayed_codes(): void
    {
        [, $partner] = $this->makeFirm();
        [$secret] = $this->enableFor($partner);

        $this->post('/login', ['email' => $partner->email, 'password' => 'Password!2345']);
        $this->get('/')->assertRedirect(route('mfa.challenge'));
        $this->get('/clients')->assertRedirect(route('mfa.challenge'));
        $this->get('/account')->assertRedirect(route('mfa.challenge')); // cannot reach MFA settings unverified

        $this->post('/mfa/challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post('/mfa/challenge', ['code' => $code])->assertRedirect();
        $this->get('/')->assertOk();

        // same code cannot be used again in a new session
        $this->post('/logout');
        $this->post('/login', ['email' => $partner->email, 'password' => 'Password!2345']);
        $this->post('/mfa/challenge', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_recovery_code_works_once(): void
    {
        [, $partner] = $this->makeFirm();
        [, $codes] = $this->enableFor($partner);

        $this->post('/login', ['email' => $partner->email, 'password' => 'Password!2345']);
        $this->post('/mfa/challenge', ['code' => strtolower($codes[0])])->assertRedirect();
        $this->assertCount(count($codes) - 1, $partner->fresh()->mfa_recovery_codes);

        $this->post('/logout');
        $this->post('/login', ['email' => $partner->email, 'password' => 'Password!2345']);
        $this->post('/mfa/challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
    }

    public function test_required_roles_cannot_disable_mfa(): void
    {
        [$firm, $partner] = $this->makeFirm();
        $this->enableFor($partner);
        $this->actingAs($partner)->withSession([MfaService::SESSION_KEY => time()])
            ->delete('/account/mfa', ['current_password' => 'Password!2345'])->assertSessionHasErrors('mfa');
        $this->assertTrue($partner->fresh()->hasMfaEnabled());

        $senior = $this->staff($firm, FirmRole::Senior);
        $this->enableFor($senior);
        $this->actingAs($senior)->withSession([MfaService::SESSION_KEY => time()])
            ->delete('/account/mfa', ['current_password' => 'Password!2345'])->assertSessionHasNoErrors();
        $this->assertFalse($senior->fresh()->hasMfaEnabled());
    }

    public function test_superadmin_requires_mfa(): void
    {
        $sa = \App\Models\User::factory()->superadmin()->create();
        $this->actingAs($sa)->get('/admin')->assertRedirect(route('mfa.setup'));
    }
}
