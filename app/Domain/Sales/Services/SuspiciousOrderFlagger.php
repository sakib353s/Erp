<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SuspiciousOrderFlag;

/**
 * SuspiciousOrderFlagger (02-28): persists the scorer's output as one
 * flag row per order at or above the threshold. Idempotent — an open
 * flag is refreshed with the latest score, a reviewed flag keeps its
 * score snapshot forever (a human decision is never overwritten by a
 * re-score, and flags are never deleted).
 */
class SuspiciousOrderFlagger
{
    public function __construct(protected SuspiciousOrderScorer $scorer) {}

    public function flag(SalesOrder $order): ?SuspiciousOrderFlag
    {
        $result = $this->scorer->score($order);

        if ($result['score'] < SuspiciousOrderScorer::THRESHOLD) {
            return null;
        }

        $existing = SuspiciousOrderFlag::query()
            ->where('sales_order_id', $order->id)
            ->first();

        if ($existing !== null && $existing->status === SuspiciousOrderFlag::STATUS_REVIEWED) {
            return $existing;
        }

        if ($existing === null) {
            return SuspiciousOrderFlag::create([
                'company_id' => $order->company_id,
                'sales_order_id' => $order->id,
                'score' => $result['score'],
                'level' => $result['level'],
                'rules' => $result['rules'],
                'status' => SuspiciousOrderFlag::STATUS_OPEN,
                'scored_at' => now(),
            ]);
        }

        $existing->fill([
            'score' => $result['score'],
            'level' => $result['level'],
            'rules' => $result['rules'],
            'scored_at' => now(),
        ])->save();

        return $existing;
    }

    /**
     * On-read backfill for orders that were never scored (02-28 view
     * materialises its own queue, refresh-on-read like TrendQuery).
     * Bounded: recent window, no-flag orders only, capped page.
     */
    public function backfill(int $companyId, int $days = 90, int $limit = 500): int
    {
        $orders = SalesOrder::query()
            ->where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereDoesntHave('suspiciousFlag')
            ->with('lines.product', 'customer')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $count = 0;
        foreach ($orders as $order) {
            if ($this->flag($order) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
