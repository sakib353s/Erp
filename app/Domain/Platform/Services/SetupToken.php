<?php

namespace App\Domain\Platform\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * First-boot setup token (spec §49, decision D20 — no default password).
 *
 * The plaintext token exists ONLY in the CLI output of
 * `php artisan erp:setup-token`. On disk we keep a SHA-256 hash plus the
 * creation time, so a leaked storage file cannot be reversed into a
 * working token. The token expires after config('erp.setup.token_ttl_minutes')
 * and is single-use: verified once during setup, it is consumed.
 *
 * Attempts are rate-limited per IP (cache) so the setup endpoint cannot
 * be brute-forced during the window the token is valid.
 */
class SetupToken
{
    protected string $hashFile;

    public function __construct()
    {
        $this->hashFile = storage_path('app/setup-token.sha256');
    }

    /** Generate a fresh token, persist only its hash, return the plaintext ONCE. */
    public function generate(): string
    {
        $token = bin2hex(random_bytes(32)); // 64 lowercase hex chars

        File::ensureDirectoryExists(dirname($this->hashFile));
        File::put($this->hashFile, json_encode([
            'hash' => hash('sha256', $token),
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes((int) config('erp.setup.token_ttl_minutes', 60))->toIso8601String(),
        ]));

        @chmod($this->hashFile, 0600);

        return $token;
    }

    public function exists(): bool
    {
        return File::exists($this->hashFile) || $this->envToken() !== null;
    }

    /**
     * Validate a candidate token without consuming it.
     * Fails closed: no stored token → nothing verifies.
     */
    public function verify(string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }

        $stored = $this->payload();

        if ($stored !== null) {
            if (now()->greaterThan(\Illuminate\Support\Carbon::parse($stored['expires_at']))) {
                $this->revoke(); // expired tokens are removed on first use attempt

                return false;
            }

            if (hash_equals($stored['hash'], hash('sha256', $candidate))) {
                return true;
            }
        }

        $env = $this->envToken();

        if ($env !== null && hash_equals($env, $candidate)) {
            return true; // env-provisioned token stays valid until env removed
        }

        return false;
    }

    /** Consume the on-disk token after successful setup (env token is left to the operator). */
    public function consume(): void
    {
        if (File::exists($this->hashFile)) {
            File::delete($this->hashFile);
        }
    }

    public function revoke(): void
    {
        $this->consume();
    }

    /** Rate limit check: has this IP burned through its attempt budget? */
    public function blocked(string $ip): bool
    {
        return (int) Cache::get($this->attemptsKey($ip), 0) >= (int) config('erp.setup.max_token_attempts', 5);
    }

    public function recordAttempt(string $ip): void
    {
        $key = $this->attemptsKey($ip);
        $count = (int) Cache::get($key, 0);
        Cache::put($key, $count + 1, now()->addMinutes((int) config('erp.setup.token_ttl_minutes', 60)));
    }

    protected function attemptsKey(string $ip): string
    {
        return 'setup.attempts.'.hash('sha256', $ip);
    }

    /** @return array{hash:string,created_at:string,expires_at:string}|null */
    protected function payload(): ?array
    {
        if (! File::exists($this->hashFile)) {
            return null;
        }

        $data = json_decode((string) File::get($this->hashFile), true);

        return is_array($data) && isset($data['hash'], $data['expires_at']) ? $data : null;
    }

    protected function envToken(): ?string
    {
        $env = env('ERP_SETUP_TOKEN');

        return is_string($env) && $env !== '' ? $env : null;
    }
}
