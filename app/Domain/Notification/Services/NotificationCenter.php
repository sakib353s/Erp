<?php

namespace App\Domain\Notification\Services;

use App\Domain\Foundation\Company;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Notification\Notification;
use App\Domain\Notification\NotificationPreference;
use App\Domain\Security\SecurityAlert;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Database\QueryException;

/**
 * Database-driven notification centre (Rule J).
 * - in-app rows with unread/read, priority, event type, persistence;
 * - per-user channel preferences (absent row = configured default —
 *   in-app on, external channels off until a real provider exists);
 * - idempotent fan-out via dedupe keys (retry-safe, Rule I).
 */
class NotificationCenter
{
    protected const RANK = ['low' => 0, 'normal' => 1, 'high' => 2, 'critical' => 3];

    public function __construct(protected NotificationRouter $router) {}

    /**
     * @param  array{priority?:string, action_url?:?string, data?:array, persistent?:bool, dedupe_key?:?string, channel?:string}  $opts
     */
    public function notify(User $user, string $eventType, string $title, ?string $body = null, array $opts = []): ?Notification
    {
        $channel = $opts['channel'] ?? 'inapp';
        $priority = $opts['priority'] ?? 'normal';

        if (! $this->channelEnabled($user, $eventType, $channel, $priority)) {
            return null;
        }

        $dedupeKey = $opts['dedupe_key'] ?? null;

        if ($dedupeKey !== null && Notification::query()->where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->exists()) {
            return null;
        }

        try {
            return Notification::create([
                'company_id' => $user->company_id,
                'user_id' => $user->id,
                'event_type' => $eventType,
                'channel' => $channel,
                'title' => mb_substr($title, 0, 191),
                'body' => $body,
                'priority' => $priority,
                'action_url' => $opts['action_url'] ?? null,
                'data' => $opts['data'] ?? null,
                'persistent' => (bool) ($opts['persistent'] ?? false),
                'dedupe_key' => $dedupeKey !== null ? hash('sha256', $dedupeKey) : null,
            ]);
        } catch (QueryException $e) {
            // Unique (user, dedupe_key) raced — another retry already delivered it.
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return null;
            }

            throw $e;
        }
    }

    /** @param  iterable<User>  $users */
    public function notifyMany(iterable $users, string $eventType, string $title, ?string $body = null, array $opts = []): int
    {
        $count = 0;

        foreach ($users as $user) {
            if ($this->notify($user, $eventType, $title, $body, $opts) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /** Raise a security alert + notify every security watcher (permission-routed). */
    public function securityAlert(string $type, string $severity, string $title, ?string $detail = null, ?int $subjectUserId = null, array $context = []): SecurityAlert
    {
        $alert = SecurityAlert::create([
            'company_id' => app(TenantContext::class)->companyId() ?? Company::current()?->id,
            'user_id' => $subjectUserId,
            'alert_type' => $type,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'ip' => request()->ip(),
            'context' => $context === [] ? null : $context,
        ]);

        if ($this->settingsBool('security_alerts_enabled', true)) {
            $this->notifyMany(
                $this->router->watchers(),
                'security.alert',
                $title,
                $detail,
                [
                    'priority' => $severity === 'critical' || $severity === 'high' ? 'critical' : 'high',
                    'action_url' => '/app/audit',
                    'data' => ['alert_id' => $alert->id, 'type' => $type],
                    'dedupe_key' => "security-alert.{$alert->id}",
                ],
            );
        }

        return $alert;
    }

    public function unreadCount(User $user): int
    {
        return Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count();
    }

    public function markRead(User $user, int $notificationId): bool
    {
        $row = Notification::query()->where('user_id', $user->id)->find($notificationId);

        if ($row === null) {
            return false;
        }

        $row->markRead();

        return true;
    }

    public function markAllRead(User $user): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    protected function channelEnabled(User $user, string $eventType, string $channel, string $priority): bool
    {
        $pref = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event_type', $eventType)
            ->where('channel', $channel)
            ->first();

        if ($pref !== null) {
            if (! $pref->is_enabled) {
                return false;
            }

            $floor = in_array($pref->priority_floor, self::RANK, true) ? $pref->priority_floor : 'low';

            return (self::RANK[$priority] ?? 1) >= (self::RANK[$floor] ?? 0);
        }

        // No explicit preference → configured default (external channels
        // default OFF — they stay truthful "not configured" until wired).
        $default = config("erp.notifications.channel_defaults.{$channel}", false);

        if ($channel === 'inapp') {
            return (bool) $default;
        }

        return (bool) app(SettingService::class)
            ->get('notifications', 'channel_'.strtolower($channel).'_enabled', $default);
    }

    protected function settingsBool(string $key, bool $default): bool
    {
        return app(SettingService::class)
            ->getBool('notifications', $key, $default);
    }
}
