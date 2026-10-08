<?php

namespace App\Domain\Security\Services;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Notification\Services\NotificationCenter;
use App\Domain\Notification\Services\NotificationRouter;
use App\Domain\Security\SecurityAlert;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Facades\Config;

/**
 * §16-36 — secure security-alert dispatch.
 *
 * Raising a security alert is the one path where "send it" can become "send a
 * thousand of them", so it is gated three ways before anything goes out:
 *
 *  · **severity list** — only the severities this system knows about may be raised;
 *    a typo or a hostile payload cannot invent a severity that bypasses policy.
 *  · **rate-limit per severity** — a flood (a brute-force burst, a misbehaving
 *    integration) must not create an alert storm; once the per-severity count for
 *    the window is reached, the existing alert is returned instead of a new one.
 *  · **dual-control for critical** — a critical alert is recorded but held
 *    (`pending_dual_control`); it is not broadcast to the watchers until a second
 *    authorized person approves it. A critical alert that auto-fired on every
 *    blip would train people to ignore the one that mattered.
 *
 * Non-critical alerts are audited (the model is Auditable) and dispatched
 * immediately; the rate-limit still applies so a non-critical storm is bounded.
 */
class SecureAlertService
{
    /** The only severities the system may raise (§16-36 severity list). */
    public const SEVERITIES = ['low', 'normal', 'high', 'critical'];

    public function __construct(
        protected TenantContext $context,
        protected NotificationCenter $notifications,
        protected SettingService $settings,
    ) {}

    /**
     * @return array{alert: SecurityAlert, dispatched: bool, rate_limited: bool}
     */
    public function raise(
        string $type,
        string $severity,
        string $title,
        ?string $detail = null,
        ?int $subjectUserId = null,
        array $context = [],
    ): array {
        abort_unless(in_array($severity, self::SEVERITIES, true), 422, "Unknown severity [{$severity}].");

        $companyId = $this->context->companyId() ?? Company::current()?->id;

        $limit = (int) config("erp.security.alerts.rate_limit.{$severity}", $severity === 'critical' ? 1 : 10);
        $window = (int) config('erp.security.alerts.rate_window_minutes', 60);

        $recent = SecurityAlert::query()
            ->where('company_id', $companyId)
            ->where('severity', $severity)
            ->where('created_at', '>=', now()->subMinutes($window))
            ->latest('id');

        if ($recent->count() >= $limit) {
            // Bounded: return the alert already on file rather than a duplicate.
            return ['alert' => $recent->firstOrFail(), 'dispatched' => false, 'rate_limited' => true];
        }

        $critical = $severity === 'critical';

        $alert = SecurityAlert::create([
            'company_id' => $companyId,
            'user_id' => $subjectUserId,
            'alert_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'ip' => request()?->ip() ?? '0.0.0.0',
            'context' => $context === [] ? null : $context,
            // Dual-control: a critical alert is held until a second person approves it.
            'status' => $critical ? 'pending_dual_control' : 'open',
        ]);

        if (! $critical) {
            $this->dispatch($alert);

            return ['alert' => $alert, 'dispatched' => true, 'rate_limited' => false];
        }

        return ['alert' => $alert, 'dispatched' => false, 'rate_limited' => false];
    }

    /** Dual control: a second authorized person releases a critical alert to the watchers. */
    public function approve(SecurityAlert $alert, int $approverId): SecurityAlert
    {
        abort_unless($alert->status === 'pending_dual_control', 422, 'Only a critical alert pending dual control can be approved.');

        $alert->update([
            'status' => 'dispatched',
            'acknowledged_by' => $approverId,
            'acknowledged_at' => now(),
        ]);

        $this->dispatch($alert);

        return $alert;
    }

    protected function dispatch(SecurityAlert $alert): void
    {
        if (! $this->settings->getBool('notifications', 'security_alerts_enabled', true)) {
            return;
        }

        $this->notifications->notifyMany(
            app(NotificationRouter::class)->watchers(),
            'security.alert',
            $alert->title,
            $alert->detail,
            [
                'priority' => $alert->severity === 'critical' || $alert->severity === 'high' ? 'critical' : 'high',
                'action_url' => '/app/audit',
                'data' => ['alert_id' => $alert->id, 'type' => $alert->alert_type],
                'dedupe_key' => "security-alert.{$alert->id}",
            ],
        );
    }
}
