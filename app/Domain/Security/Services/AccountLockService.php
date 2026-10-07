<?php

namespace App\Domain\Security\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Security\AuthEvent;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Account lock policy (spec section C): consecutive failures lock the
 * account for a configurable window; a successful login resets the
 * counter. All outcomes are recorded in auth_events + audit_events.
 */
class AccountLockService
{
    public function __construct(
        protected \App\Domain\Settings\Services\SettingService $settings,
        protected AuditRecorder $audit,
    ) {}

    /** @return \DateTimeInterface|null when the account unlocks (null = not locked) */
    public function registerFailure(?string $userId, string $ip, ?string $email, string $reason): ?\DateTimeInterface
    {
        $max = $this->settings->getInt('security', 'lockout_max_attempts', 5);
        $lockMinutes = $this->settings->getInt('security', 'lockout_minutes', 15);

        AuthEvent::create([
            'company_id' => \App\Domain\Foundation\Company::current()?->id,
            'user_id' => $userId,
            'event_type' => 'login_failed',
            'email_attempt' => $email,
            'ip' => $ip,
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 191),
            'reason' => $reason,
        ]);

        $this->audit->record([
            'action' => 'auth.login_failed',
            'entity_type' => 'user',
            'entity_id' => $userId,
            'actor_type' => $userId !== null ? 'user' : 'public',
            'actor_id' => $userId,
            'result' => 'failure',
            'reason' => $reason,
            'ip' => $ip,
        ]);

        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        $user->failed_login_count = $user->failed_login_count + 1;

        $lockedUntil = null;

        if ($user->failed_login_count >= $max) {
            $lockedUntil = now()->addMinutes($lockMinutes);
            $user->locked_until = $lockedUntil;
        }

        $user->saveQuietly();

        if ($lockedUntil !== null) {
            $this->audit->record([
                'action' => 'auth.lockout',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'result' => 'failure',
                'reason' => 'locked until '.$lockedUntil->toDateTimeString(),
                'ip' => $ip,
            ]);
        }

        return $lockedUntil;
    }

    public function registerSuccess(User $user, string $ip): void
    {
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->last_login_at = now();
        $user->saveQuietly();

        AuthEvent::create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'event_type' => 'login_success',
            'email_attempt' => $user->email,
            'ip' => $ip,
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 191),
        ]);
    }
}
