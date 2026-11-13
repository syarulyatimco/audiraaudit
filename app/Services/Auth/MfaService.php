<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Audit\ActivityLogger;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) multi-factor authentication with single-use recovery codes.
 * Secrets are stored with Laravel's encrypted cast; recovery codes are stored as SHA-256 hashes.
 */
class MfaService
{
    public const SESSION_KEY = 'mfa_verified_at';

    public function __construct(private Google2FA $totp, private ActivityLogger $logger) {}

    public function newSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $uri = $this->totp->getQRCodeUrl(config('audira.mfa.issuer'), $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** @return list<string> plain recovery codes (shown once) */
    public function enable(User $user, string $secret, string $code): array
    {
        $step = $this->totp->verifyKeyNewer($secret, $this->normalise($code), 0, 1);
        if ($step === false) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }

        $codes = $this->generateRecoveryCodes();
        $user->forceFill([
            'mfa_secret' => $secret,
            'mfa_enabled_at' => now(),
            'mfa_last_timestep' => $step,
            'mfa_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
        ])->save();
        $this->logger->log('auth.mfa_enabled', "{$user->name} enabled two-factor authentication", $user, [], $user, $user->firm_id);

        return $codes;
    }

    /** Verify a TOTP code (replay-protected) or consume a recovery code. */
    public function verify(User $user, string $input): bool
    {
        if (! $user->hasMfaEnabled()) {
            return false;
        }

        $input = trim($input);
        if (preg_match('/^\d{6}$/', $this->normalise($input))) {
            // Passing the last used time step (0 if none) rejects any code at or before it: no replay.
            $step = $this->totp->verifyKeyNewer($user->mfa_secret, $this->normalise($input), (int) $user->mfa_last_timestep, 1);
            if ($step !== false) {
                $user->forceFill(['mfa_last_timestep' => $step])->save();

                return true;
            }

            return false;
        }

        $hash = hash('sha256', strtoupper($input));
        $codes = $user->mfa_recovery_codes ?? [];
        if (in_array($hash, $codes, true)) {
            $user->forceFill(['mfa_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();
            $this->logger->log('auth.mfa_recovery_used', "{$user->name} signed in with a recovery code", $user, ['remaining' => count($codes) - 1], $user, $user->firm_id);

            return true;
        }

        return false;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $user->forceFill(['mfa_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();
        $this->logger->log('auth.mfa_recovery_regenerated', "{$user->name} regenerated recovery codes", $user, [], $user, $user->firm_id);

        return $codes;
    }

    public function disable(User $user): void
    {
        if ($user->requiresMfa()) {
            throw ValidationException::withMessages(['mfa' => 'Two-factor authentication is mandatory for your role and cannot be turned off.']);
        }
        $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_recovery_codes' => null, 'mfa_last_timestep' => null])->save();
        $this->logger->log('auth.mfa_disabled', "{$user->name} disabled two-factor authentication", $user, [], $user, $user->firm_id);
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        return array_map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)), range(1, config('audira.mfa.recovery_codes')));
    }

    private function normalise(string $code): string
    {
        return preg_replace('/\s+/', '', $code);
    }
}
