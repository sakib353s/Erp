<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreateCoupon (02-101). Company-scoped coupon with type validation.
 * DOC/config only — no stock/GL. Usage recorded separately on redeem.
 */
class CreateCoupon
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   code: string,
     *   type: string,
     *   value?: float,
     *   buy_qty?: int|null,
     *   get_qty?: int|null,
     *   min_subtotal?: float|null,
     *   max_uses?: int|null,
     *   starts_at?: string|null,
     *   ends_at?: string|null,
     *   is_active?: bool,
     *   description?: string|null,
     * } $payload
     */
    public function handle(array $payload, Request $request): Coupon
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $type = (string) ($payload['type'] ?? '');

        if ($code === '') {
            throw new RuntimeException('Coupon code is required.');
        }

        if (! in_array($type, Coupon::TYPES, true)) {
            throw new RuntimeException('Coupon type must be percent_off, fixed_off, free_shipping, or buy_x_get_y.');
        }

        $value = (float) ($payload['value'] ?? 0);
        $buyQty = isset($payload['buy_qty']) && $payload['buy_qty'] !== null ? (int) $payload['buy_qty'] : null;
        $getQty = isset($payload['get_qty']) && $payload['get_qty'] !== null ? (int) $payload['get_qty'] : null;

        if ($type === 'percent_off' && ($value <= 0 || $value > 100)) {
            throw new RuntimeException('Percent-off value must be between 0 (exclusive) and 100.');
        }
        if ($type === 'fixed_off' && $value <= 0) {
            throw new RuntimeException('Fixed-off value must be greater than zero.');
        }
        if ($type === 'buy_x_get_y') {
            if ($buyQty === null || $buyQty < 1 || $getQty === null || $getQty < 1) {
                throw new RuntimeException('Buy X Get Y requires buy_qty and get_qty of at least 1.');
            }
            if ($value <= 0 || $value > 100) {
                throw new RuntimeException('Buy X Get Y value (percent off free units) must be between 0 (exclusive) and 100.');
            }
        }

        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;
        if ($startsAt !== null && $endsAt !== null && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw new RuntimeException('Coupon end date cannot precede start date.');
        }

        return DB::transaction(function () use ($payload, $companyId, $code, $type, $value, $buyQty, $getQty, $startsAt, $endsAt, $request) {
            $exists = Coupon::query()
                ->where('company_id', $companyId)
                ->where('code', $code)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw new RuntimeException("Coupon code [{$code}] already exists.");
            }

            $coupon = Coupon::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'code' => $code,
                'type' => $type,
                'value' => in_array($type, ['free_shipping'], true) ? 0 : number_format($value, 4, '.', ''),
                'buy_qty' => $type === 'buy_x_get_y' ? $buyQty : null,
                'get_qty' => $type === 'buy_x_get_y' ? $getQty : null,
                'min_subtotal' => isset($payload['min_subtotal']) && $payload['min_subtotal'] !== null
                    ? number_format((float) $payload['min_subtotal'], 4, '.', '')
                    : null,
                'max_uses' => isset($payload['max_uses']) && $payload['max_uses'] !== null
                    ? (int) $payload['max_uses']
                    : null,
                'used_count' => 0,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'description' => $payload['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $this->audit->record([
                'action' => 'sales.coupon_created',
                'entity_type' => 'coupon',
                'entity_id' => $coupon->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'code' => $code,
                    'type' => $type,
                    'value' => (float) $coupon->value,
                    'is_active' => (bool) $coupon->is_active,
                ],
            ]);

            return $coupon;
        });
    }
}
