<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Coupon;
use App\Domain\Sales\CouponUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RedeemCoupon (02-103 usage tracking). Validates the coupon and
 * records a usage row + used_count bump against a source document.
 * Call inside the document's transaction after totals are known.
 */
class RedeemCoupon
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(
        Coupon $coupon,
        string $sourceType,
        int $sourceId,
        float $discountAmount,
        ?int $customerId,
        Request $request,
    ): CouponUsage {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($coupon, $companyId, $sourceType, $sourceId, $discountAmount, $customerId, $request) {
            $fresh = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();

            $already = CouponUsage::query()
                ->where('coupon_id', $fresh->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();

            if ($already !== null) {
                return $already;
            }

            if ($fresh->max_uses !== null && (int) $fresh->used_count >= (int) $fresh->max_uses) {
                throw new RuntimeException("Coupon [{$fresh->code}] has reached its usage limit.");
            }

            $usage = CouponUsage::create([
                'company_id' => $companyId,
                'coupon_id' => $fresh->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'customer_id' => $customerId,
                'discount_amount' => number_format(max(0, $discountAmount), 4, '.', ''),
                'used_at' => now(),
            ]);

            $fresh->used_count = (int) $fresh->used_count + 1;
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.coupon_redeemed',
                'entity_type' => 'coupon',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'code' => $fresh->code,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'discount_amount' => (float) $usage->discount_amount,
                    'used_count' => $fresh->used_count,
                ],
            ]);

            return $usage;
        });
    }
}
