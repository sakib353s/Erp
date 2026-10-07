<?php

namespace App\Domain\Security\Services;

use App\Domain\Foundation\User;
use App\Domain\Security\AuthEvent;

/**
 * Suspicious-login heuristics (spec section C): flags logins from IPs or
 * user-agents the account has not been seen with before (within the
 * configured lookback window). First-ever login is treated as baseline.
 */
class SuspiciousLoginDetector
{
    public const REASON_NEW_IP = 'new_ip_address';
    public const REASON_NEW_DEVICE = 'new_device_or_agent';

    /** @return array{suspicious:bool, reason:?string} */
    public function detect(User $user, string $ip, string $userAgent): array
    {
        if (! (bool) config('erp.security.suspicious_login.alert_on_new_device', true)) {
            return ['suspicious' => false, 'reason' => null];
        }

        $knownDays = (int) config('erp.security.suspicious_login.known_days', 90);

        $prior = AuthEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'login_success')
            ->where('created_at', '>=', now()->subDays($knownDays))
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['ip', 'user_agent']);

        if ($prior->isEmpty()) {
            return ['suspicious' => false, 'reason' => null]; // baseline login
        }

        if (! $prior->contains(fn ($row) => $row->ip === $ip)) {
            return ['suspicious' => true, 'reason' => self::REASON_NEW_IP];
        }

        if (! $prior->contains(fn ($row) => $row->user_agent === $userAgent)) {
            return ['suspicious' => true, 'reason' => self::REASON_NEW_DEVICE];
        }

        return ['suspicious' => false, 'reason' => null];
    }
}
