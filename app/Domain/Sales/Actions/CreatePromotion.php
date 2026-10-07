<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CreatePromotion (02-105). Windowed, branch-scoped promotion with
 * optional product items and priority. DOC/config only — discount applied
 * by PromotionEngine at document create time.
 */
class CreatePromotion
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   name: string,
     *   code?: string|null,
     *   type: string,
     *   kind?: string,
     *   value: float,
     *   min_subtotal?: float|null,
     *   priority?: int,
     *   branch_id?: int|null,
     *   starts_at?: string|null,
     *   ends_at?: string|null,
     *   is_active?: bool,
     *   description?: string|null,
     *   product_ids?: list<int>,
     * } $payload
     */
    public function handle(array $payload, Request $request): Promotion
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $name = trim((string) ($payload['name'] ?? ''));
        $type = (string) ($payload['type'] ?? '');
        $kind = (string) ($payload['kind'] ?? 'standard');
        $code = $payload['code'] ?? null;
        $code = $code !== null && $code !== '' ? strtoupper(trim((string) $code)) : null;
        $value = (float) ($payload['value'] ?? 0);
        $priority = isset($payload['priority']) && $payload['priority'] !== null
            ? max(1, min(9999, (int) $payload['priority']))
            : 100;

        if ($name === '') {
            throw new RuntimeException('Promotion name is required.');
        }

        if (! in_array($type, Promotion::TYPES, true)) {
            throw new RuntimeException('Promotion type must be percent_off or fixed_off.');
        }

        if (! in_array($kind, Promotion::KINDS, true)) {
            throw new RuntimeException('Promotion kind must be standard, seasonal, or flash.');
        }

        if ($type === 'percent_off' && ($value <= 0 || $value > 100)) {
            throw new RuntimeException('Percent-off value must be between 0 (exclusive) and 100.');
        }
        if ($type === 'fixed_off' && $value <= 0) {
            throw new RuntimeException('Fixed-off value must be greater than zero.');
        }

        $startsAt = $payload['starts_at'] ?? null;
        $endsAt = $payload['ends_at'] ?? null;

        if ($kind === 'flash' && ($startsAt === null || $endsAt === null)) {
            throw new RuntimeException('Flash sales require starts_at and ends_at.');
        }

        if ($startsAt !== null && $endsAt !== null && strtotime((string) $endsAt) <= strtotime((string) $startsAt)) {
            throw new RuntimeException('Promotion end must be after start.');
        }

        $productIds = array_values(array_unique(array_map(
            'intval',
            (array) ($payload['product_ids'] ?? []),
        )));

        return DB::transaction(function () use ($payload, $companyId, $name, $type, $kind, $code, $value, $priority, $startsAt, $endsAt, $productIds, $request) {
            if ($code !== null) {
                $exists = Promotion::query()
                    ->where('company_id', $companyId)
                    ->where('code', $code)
                    ->lockForUpdate()
                    ->exists();
                if ($exists) {
                    throw new RuntimeException("Promotion code [{$code}] already exists.");
                }
            }

            $promotion = Promotion::create([
                'company_id' => $companyId,
                'branch_id' => $payload['branch_id'] ?? null,
                'name' => $name,
                'code' => $code,
                'type' => $type,
                'kind' => $kind,
                'value' => number_format($value, 4, '.', ''),
                'min_subtotal' => isset($payload['min_subtotal']) && $payload['min_subtotal'] !== null
                    ? number_format((float) $payload['min_subtotal'], 4, '.', '')
                    : null,
                'priority' => $priority,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'description' => $payload['description'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($productIds as $productId) {
                $promotion->items()->create([
                    'company_id' => $companyId,
                    'product_id' => $productId,
                    'min_qty' => 1,
                ]);
            }

            $this->audit->record([
                'action' => 'sales.promotion_created',
                'entity_type' => 'promotion',
                'entity_id' => $promotion->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'name' => $promotion->name,
                    'type' => $promotion->type,
                    'kind' => $promotion->kind,
                    'value' => (float) $promotion->value,
                    'priority' => (int) $promotion->priority,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ],
            ]);

            return $promotion;
        });
    }
}
