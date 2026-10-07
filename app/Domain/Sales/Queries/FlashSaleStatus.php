<?php

namespace App\Domain\Sales\Queries;

use App\Domain\Sales\Promotion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * FlashSaleStatus (02-106). Countdown inputs come only from DB
 * starts_at/ends_at — client JS counts down from these absolute timestamps.
 */
class FlashSaleStatus
{
    /**
     * @return array{
     *   rows: Collection<int, array<string, mixed>>,
     *   active_count: int,
     *   upcoming_count: int,
     *   method: string,
     *   server_time: string,
     * }
     */
    public function forCompany(int $companyId, ?string $at = null): array
    {
        $now = $at !== null ? Carbon::parse($at) : now();

        $promotions = Promotion::query()
            ->where('company_id', $companyId)
            ->where('kind', 'flash')
            ->orderByDesc('starts_at')
            ->get();

        $rows = $promotions->map(function (Promotion $promotion) use ($now) {
            $startsAt = $promotion->starts_at;
            $endsAt = $promotion->ends_at;
            $state = 'ended';
            $remainingSeconds = 0;

            if ($startsAt !== null && $endsAt !== null) {
                if ($now->lt($startsAt)) {
                    $state = 'upcoming';
                    $remainingSeconds = max(0, (int) $now->diffInSeconds($endsAt, false));
                } elseif ($now->lte($endsAt)) {
                    $state = 'active';
                    $remainingSeconds = max(0, (int) $now->diffInSeconds($endsAt, false));
                }
            }

            return [
                'promotion' => $promotion,
                'state' => $state,
                'remaining_seconds' => $remainingSeconds,
                'starts_at' => $startsAt?->toIso8601String(),
                'ends_at' => $endsAt?->toIso8601String(),
                'ends_at_db' => $endsAt?->toDateTimeString(),
            ];
        })->values();

        return [
            'rows' => $rows,
            'active_count' => $rows->filter(fn (array $r) => $r['state'] === 'active')->count(),
            'upcoming_count' => $rows->filter(fn (array $r) => $r['state'] === 'upcoming')->count(),
            'method' => 'remaining_seconds = ends_at(server DB) − now(server); no client-authored timers',
            'server_time' => $now->toIso8601String(),
        ];
    }
}
