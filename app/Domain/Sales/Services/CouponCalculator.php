<?php

namespace App\Domain\Sales\Services;

use App\Domain\Sales\Coupon;
use RuntimeException;

/**
 * CouponCalculator (02-102). Server-authoritative discount for a
 * coupon type against a net subtotal + shipping.
 *
 * Returns:
 *  - discount: amount reduced from subtotal (doc-level)
 *  - free_shipping: true when type zeros shipping
 *  - shipping_for_totals: shipping to pass into TotalsCalculator
 *
 * buy_x_get_y needs priced cart lines (qty + unit_price after line discount).
 */
class CouponCalculator
{
    /**
     * @param array<int, array{
     *   qty?: float|int|string,
     *   unit_price?: float|int|string,
     *   discount?: float|int|string
     * }> $lines
     * @return array{discount: float, free_shipping: bool, shipping_for_totals: float}
     */
    public function calculate(Coupon $coupon, float $netSubtotal, float $shipping, array $lines = []): array
    {
        $shipping = max(0.0, round($shipping, 4));
        $netSubtotal = max(0.0, round($netSubtotal, 4));

        return match ($coupon->type) {
            'percent_off' => [
                'discount' => round($netSubtotal * ((float) $coupon->value) / 100, 4),
                'free_shipping' => false,
                'shipping_for_totals' => $shipping,
            ],
            'fixed_off' => [
                'discount' => round(min($netSubtotal, (float) $coupon->value), 4),
                'free_shipping' => false,
                'shipping_for_totals' => $shipping,
            ],
            'free_shipping' => [
                'discount' => 0.0,
                'free_shipping' => true,
                'shipping_for_totals' => 0.0,
            ],
            'buy_x_get_y' => [
                'discount' => round(min($netSubtotal, $this->buyXGetYDiscount($coupon, $lines, $netSubtotal)), 4),
                'free_shipping' => false,
                'shipping_for_totals' => $shipping,
            ],
            default => throw new RuntimeException("Unsupported coupon type [{$coupon->type}]."),
        };
    }

    /**
     * Buy X Get Y: for every (buy_qty + get_qty) cart units, free the
     * get_qty cheapest units at their net unit price (value% off free units,
     * default 100 = fully free).
     *
     * @param  array<int, array{qty?: float|int|string, unit_price?: float|int|string, discount?: float|int|string}>  $lines
     */
    protected function buyXGetYDiscount(Coupon $coupon, array $lines, float $netSubtotal): float
    {
        $buyQty = (int) $coupon->buy_qty;
        $getQty = (int) $coupon->get_qty;

        if ($buyQty < 1 || $getQty < 1) {
            throw new RuntimeException("Coupon [{$coupon->code}] requires buy_qty and get_qty for buy_x_get_y.");
        }

        if ($lines === []) {
            throw new RuntimeException("Coupon [{$coupon->code}] buy_x_get_y requires cart lines.");
        }

        $units = [];
        foreach ($lines as $line) {
            $qty = (float) ($line['qty'] ?? 0);
            $unit = (float) ($line['unit_price'] ?? 0);
            $discount = (float) ($line['discount'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $netUnit = $unit - ($discount / $qty);
            $left = $qty;
            while ($left > 1e-9) {
                $take = min(1.0, $left);
                $units[] = max(0.0, $netUnit);
                $left = round($left - $take, 4);
            }
        }

        $totalUnits = count($units);
        $group = $buyQty + $getQty;
        if ($group < 1 || $totalUnits < $group) {
            return 0.0;
        }

        $freeUnits = intdiv($totalUnits, $group) * $getQty;
        if ($freeUnits <= 0) {
            return 0.0;
        }

        sort($units);
        $freeValue = 0.0;
        for ($i = 0; $i < $freeUnits; $i++) {
            $freeValue += $units[$i];
        }

        $percent = $coupon->value !== null ? (float) $coupon->value : 100.0;

        return round($freeValue * $percent / 100, 4);
    }
}
