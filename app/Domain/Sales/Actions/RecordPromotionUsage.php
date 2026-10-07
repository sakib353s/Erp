<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Promotion;
use App\Domain\Sales\PromotionUsage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * RecordPromotionUsage (02-105/02-107). Idempotent usage row for real
 * attribution in PromotionReport. Call inside the document transaction.
 */
class RecordPromotionUsage
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(
        Promotion $promotion,
        string $sourceType,
        int $sourceId,
        float $discountAmount,
        ?int $customerId,
        Request $request,
    ): PromotionUsage {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($promotion, $companyId, $sourceType, $sourceId, $discountAmount, $customerId, $request) {
            $already = PromotionUsage::query()
                ->where('promotion_id', $promotion->id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->first();

            if ($already !== null) {
                return $already;
            }

            $usage = PromotionUsage::create([
                'company_id' => $companyId,
                'promotion_id' => $promotion->id,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'customer_id' => $customerId,
                'discount_amount' => number_format(max(0, $discountAmount), 4, '.', ''),
                'used_at' => now(),
            ]);

            $this->audit->record([
                'action' => 'sales.promotion_redeemed',
                'entity_type' => 'promotion',
                'entity_id' => $promotion->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'name' => $promotion->name,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'discount_amount' => (float) $usage->discount_amount,
                ],
            ]);

            return $usage;
        });
    }
}
