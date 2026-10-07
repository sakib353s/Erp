<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\SalesOrder;

/**
 * SuspiciousOrderScorer (02-28): a deterministic, explainable rule
 * scorer over real order data. Every fired rule carries its weight and
 * an evidence string with the actual numbers it saw — the score is the
 * clamped sum of those weights, never a black box. No rule ever mutates
 * or deletes anything; scoring is pure read-side evaluation.
 */
class SuspiciousOrderScorer
{
    /** Minimum score that materialises a flag row. */
    public const THRESHOLD = 40;

    /** Score at or above which a flag is level "high" (else "elevated"). */
    public const LEVEL_HIGH = 70;

    /** @return array{score: int, level: string, rules: list<array{key: string, label: string, weight: int, evidence: string}>} */
    public function score(SalesOrder $order): array
    {
        $order->loadMissing(['lines.product', 'customer']);

        $rules = [];
        $total = (float) $order->grand_total;

        $bulkLine = $order->lines->first(fn ($line) => (float) $line->qty >= 100);
        if ($bulkLine !== null) {
            $rules[] = $this->rule(
                'bulk_qty',
                'Bulk quantity',
                30,
                sprintf(
                    'Line %d (%s) qty %s is at or above the 100-unit bulk threshold',
                    $bulkLine->line_no,
                    $bulkLine->product?->name ?? 'product #'.$bulkLine->product_id,
                    $this->trimNum((float) $bulkLine->qty),
                ),
            );
        }

        if ($total >= 5000 && fmod($total, 1000) === 0.0) {
            $rules[] = $this->rule(
                'round_total',
                'Round-thousand total',
                20,
                sprintf('Total %s is an exact multiple of 1,000', number_format($total, 2)),
            );
        }

        $customer = $order->customer;
        if ($customer !== null
            && $total >= 10000
            && $customer->created_at !== null
            && $customer->created_at->gte(now()->subDays(7))
        ) {
            $rules[] = $this->rule(
                'new_customer_value',
                'New customer, high value',
                25,
                sprintf(
                    'Customer %s registered %d day(s) ago; total %s is at or above 10,000',
                    $customer->name,
                    (int) $customer->created_at->diffInDays(now()),
                    number_format($total, 2),
                ),
            );
        }

        $hour = (int) $order->created_at?->hour;
        if ($hour >= 0 && $hour < 5) {
            $rules[] = $this->rule(
                'night_created',
                'Unusual hour',
                15,
                sprintf(
                    'Created at %s (00:00–04:59 window)',
                    $order->created_at->format('H:i'),
                ),
            );
        }

        if ($order->lines->count() >= 25) {
            $rules[] = $this->rule(
                'high_line_count',
                'Very large cart',
                15,
                sprintf('%d lines on a single order', $order->lines->count()),
            );
        }

        if ($customer !== null) {
            $cancelled = SalesOrder::query()
                ->where('company_id', $order->company_id)
                ->where('customer_id', $order->customer_id)
                ->where('id', '!=', $order->id)
                ->where('status', 'cancelled')
                ->where('created_at', '>=', now()->subDays(90))
                ->count();

            if ($cancelled >= 2) {
                $rules[] = $this->rule(
                    'repeat_cancellations',
                    'Repeat cancellations',
                    20,
                    sprintf('Customer has %d cancelled orders in the last 90 days', $cancelled),
                );
            }
        }

        $score = min(100, array_sum(array_column($rules, 'weight')));

        return [
            'score' => $score,
            'level' => $score >= self::LEVEL_HIGH ? 'high' : 'elevated',
            'rules' => $rules,
        ];
    }

    /** @return array{key: string, label: string, weight: int, evidence: string} */
    protected function rule(string $key, string $label, int $weight, string $evidence): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'weight' => $weight,
            'evidence' => $evidence,
        ];
    }

    protected function trimNum(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
