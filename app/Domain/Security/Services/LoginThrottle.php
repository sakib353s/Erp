<?php

namespace App\Domain\Security\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Security\AuthEvent;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login throttling / brute-force protection (spec section C).
 * Immediate layer: per-IP+email rate limiter (see AccountLockService for
 * the persistent per-account layer).
 */
class LoginThrottle
{
    public function __construct(protected AuditRecorder $audit) {}

    public function tooManyAttempts(string $ip, string $email): bool
    {
        return RateLimiter::tooManyAttempts($this->key($ip, $email), $this->maxHits());
    }

    public function hit(string $ip, string $email): int
    {
        return RateLimiter::hit($this->key($ip, $email), $this->decaySeconds());
    }

    public function clear(string $ip, string $email): void
    {
        RateLimiter::clear($this->key($ip, $email));
    }

    public function retryAfter(string $ip, string $email): int
    {
        return RateLimiter::availableIn($this->key($ip, $email));
    }

    protected function key(string $ip, string $email): string
    {
        return 'login:'.sha1(strtolower($email).'|'.$ip);
    }

    protected function maxHits(): int
    {
        return (int) config('erp.security.lockout.max_throttle_hits', 20);
    }

    protected function decaySeconds(): int
    {
        return (int) config('erp.security.lockout.decay_seconds', 60);
    }
}
