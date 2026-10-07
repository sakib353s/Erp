<?php

namespace App\Domain\Sales\Services;

use App\Domain\Masters\Customer;
use App\Domain\Sales\Coupon;
use Carbon\Carbon;
use RuntimeException;

/**
 * Server-side coupon validation (02-101). Never trusts client totals;
 * caller supplies subtotal after line/doc discounts already applied
 * for min_subtotal checks (uses pre-coupon netsubtotal argument).
 */
class CouponValidator
{
    /**
     * @throws RuntimeException
     */
    public function validate(
        string $code,
        float $netSubtotal,
        ?Customer $customer,
        ?string $at,
        int $companyId,
    ): Coupon {
        $code = strtoupper(trim($code));
        if ($code === '') {
            throw new RuntimeException('Coupon code is required.');
        }

        $coupon = Coupon::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->first();

        if ($coupon === null) {
            throw new RuntimeException("Coupon [{$code}] not found.");
        }

        if (! $coupon->is_active) {
            throw new RuntimeException("Coupon [{$code}] is inactive.");
        }

        $now = Carbon::parse($at ?? now());

        if ($coupon->starts_at !== null && $now->lt($coupon->starts_at)) {
            throw new RuntimeException("Coupon [{$code}] is not yet active.");
        }

        if ($coupon->ends_at !== null && $now->gt($coupon->ends_at)) {
            throw new RuntimeException("Coupon [{$code}] has expired.");
        }

        if ($coupon->max_uses !== null && (int) $coupon->used_count >= (int) $coupon->max_uses) {
            throw new RuntimeException("Coupon [{$code}] has reached its usage limit.");
        }

        if ($coupon->min_subtotal !== null && $netSubtotal + 1e-9 < (float) $coupon->min_subtotal) {
            throw new RuntimeException(sprintf(
                'Coupon [%s] requires a minimum subtotal of %.2f.',
                $code,
                (float) $coupon->min_subtotal,
            ));
        }

        if (! in_array($coupon->type, Coupon::TYPES, true)) {
            throw new RuntimeException("Coupon [{$code}] has an unsupported type.");
        }

        if ($coupon->type === 'buy_x_get_y'
            && ((int) $coupon->buy_qty < 1 || (int) $coupon->get_qty < 1)) {
            throw new RuntimeException("Coupon [{$code}] is not configured for buy_x_get_y.");
        }

        return $coupon;
    }
}
