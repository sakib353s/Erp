<?php

namespace App\Domain\Security\Listeners;

use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Security\AuthEvent;
use App\Domain\Security\Events\LoginFailed;
use App\Domain\Security\Events\LoginSucceeded;
use App\Domain\Security\Services\SecureAlertService;
use App\Domain\Settings\Services\SettingService;

/**
 * Security alert generation (section C/H):
 *  - suspicious login (new IP/device) → high-severity alert,
 *  - brute-force attempts from one IP → high-severity alert (rate-limited
 *    by checking for a recent identical alert — no alert storms).
 *
 * Listeners run post-commit via the outbox; alert creation is guarded so
 * retries never duplicate an existing recent alert.
 */
class RaiseSecurityAlerts
{
    public function __construct(
        protected NotificationCenter $notifications,
        protected SettingService $settings,
    ) {}

    public function onLoginSucceeded(LoginSucceeded $event): void
    {
        if (! $event->suspicious) {
            return;
        }

        $recent = app(\App\Domain\Security\Services\SecureAlertService::class)->raise(
            type: match ($event->reason) {
                'new_ip_address' => 'login.new_ip',
                default => 'login.new_device',
            },
            severity: 'high',
            title: 'Suspicious login detected',
            detail: "Account logged in from an unrecognised source (IP {$event->ip}). Reason: {$event->reason}.",
            subjectUserId: $event->userId,
            context: ['ip' => $event->ip, 'reason' => $event->reason, 'user_agent' => $event->userAgent],
        );
    }

    public function onLoginFailed(LoginFailed $event): void
    {
        $threshold = (int) config('erp.security.lockout.max_throttle_hits', 20);

        $recentFailures = AuthEvent::query()
            ->where('event_type', 'login_failed')
            ->where('ip', $event->ip)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentFailures < $threshold) {
            return;
        }

        $existing = \App\Domain\Security\SecurityAlert::query()
            ->where('alert_type', 'login.brute_force')
            ->where('ip', $event->ip)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($existing) {
            return; // already alerted for this burst
        }

        app(\App\Domain\Security\Services\SecureAlertService::class)->raise(
            type: 'login.brute_force',
            severity: 'critical',
            title: 'Possible brute-force attack',
            detail: "{$recentFailures} failed login attempts from IP {$event->ip} within 10 minutes.",
            subjectUserId: $event->userId,
            context: ['ip' => $event->ip, 'failures' => $recentFailures],
        );
    }
}
