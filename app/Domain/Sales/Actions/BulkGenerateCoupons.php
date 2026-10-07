<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Coupon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BulkGenerateCoupons (02-104). Secure random codes with high entropy,
 * company uniqueness, optional shared type/window. Sync-safe for tests
 * (no queue worker required); audit records the batch.
 */
class BulkGenerateCoupons
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   count: int,
     *   prefix?: string,
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
     * @return array{codes: list<string>, created: int}
     */
    public function handle(array $payload, Request $request): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $count = (int) ($payload['count'] ?? 0);
        if ($count < 1 || $count > 500) {
            throw new RuntimeException('Bulk coupon count must be between 1 and 500.');
        }

        $type = (string) ($payload['type'] ?? 'percent_off');
        if (! in_array($type, Coupon::TYPES, true)) {
            throw new RuntimeException('Bulk coupon type is unsupported.');
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
        if ($type === 'buy_x_get_y' && ($buyQty === null || $buyQty < 1 || $getQty === null || $getQty < 1)) {
            throw new RuntimeException('Buy X Get Y requires buy_qty and get_qty of at least 1.');
        }

        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($payload['prefix'] ?? '')) ?? '');
        if (strlen($prefix) > 24) {
            throw new RuntimeException('Bulk coupon prefix is too long.');
        }

        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;
        if ($startsAt !== null && $endsAt !== null && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw new RuntimeException('Coupon end date cannot precede start date.');
        }

        return DB::transaction(function () use ($payload, $companyId, $count, $type, $value, $buyQty, $getQty, $prefix, $startsAt, $endsAt, $request) {
            $codes = [];
            $maxAttempts = $count * 20 + 50;

            for ($attempt = 0; $attempt < $maxAttempts && count($codes) < $count; $attempt++) {
                // 12 hex chars = 48 bits entropy + optional prefix
                $code = $prefix.bin2hex(random_bytes(6));
                if (strlen($code) > 48) {
                    $code = substr($code, 0, 48);
                }

                $exists = Coupon::query()
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->exists();

                if ($exists) {
                    continue;
                }

                Coupon::create([
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
                        : 1,
                    'used_count' => 0,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'is_active' => (bool) ($payload['is_active'] ?? true),
                    'description' => $payload['description'] ?? 'Bulk generated',
                    'created_by' => $request->user()->id,
                ]);

                $codes[] = $code;
            }

            if (count($codes) < $count) {
                throw new RuntimeException('Unable to generate unique coupon codes; try a smaller batch.');
            }

            $this->audit->record([
                'action' => 'sales.coupons_bulk_generated',
                'entity_type' => 'coupon_batch',
                'entity_id' => 0,
                'actor_id' => $request->user()->id,
                'after' => [
                    'count' => count($codes),
                    'type' => $type,
                    'value' => $value,
                    'prefix' => $prefix,
                ],
            ]);

            return ['codes' => $codes, 'created' => count($codes)];
        });
    }
}
