<?php

namespace App\Domain\Security\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\User;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Configurable session policy (spec section C):
 *  - idle timeout (per request enforcement),
 *  - max concurrent sessions per user (prunes oldest sessions at login).
 */
class SessionPolicyService
{
    public function __construct(
        protected SettingService $settings,
        protected AuditRecorder $audit,
    ) {}

    /** Returns true when the current session is still within the idle window. */
    public function enforceIdle(): bool
    {
        if (! Auth::check()) {
            return true;
        }

        $timeout = $this->settings->getInt('security', 'session_idle_minutes', 30);
        $lastSeen = session('last_seen_at');

        if ($timeout > 0 && $lastSeen !== null && now()->diffInMinutes(\Illuminate\Support\Carbon::parse($lastSeen)) >= $timeout) {
            return false;
        }

        session(['last_seen_at' => now()->toIso8601String()]);

        return true;
    }

    public function expireCurrentSession(): void
    {
        $user = Auth::user();

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->audit->record([
            'action' => 'security.session_expired',
            'entity_type' => 'user',
            'entity_id' => $user?->id,
            'actor_id' => $user?->id,
            'reason' => 'idle timeout',
        ]);
    }

    /**
     * Keep at most N active sessions per user; prune the oldest ones.
     * Returns the number of pruned sessions.
     */
    public function pruneConcurrent(User $user, ?string $keepSessionId = null): int
    {
        $max = $this->settings->getInt('security', 'session_max_concurrent', 3);

        $expired = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->skip($max)
            ->take(100)
            ->get(['id']);

        $ids = collect($expired)->pluck('id')
            ->reject(fn ($id) => $keepSessionId !== null && $id === $keepSessionId)
            ->values();

        if ($ids->isEmpty()) {
            return 0;
        }

        DB::table('sessions')->whereIn('id', $ids->all())->delete();

        $this->audit->record([
            'action' => 'security.sessions_pruned',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'reason' => 'max concurrent sessions ('.$max.')',
            'after' => ['pruned' => $ids->count()],
        ]);

        return $ids->count();
    }
}
