<?php

namespace App\Domain\Purchase\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchase order lifecycle (§03).
 *
 * Rules owned here:
 *   · every line's money is recomputed from qty × price − discount + tax; a
 *     client-supplied total is ignored, never trusted;
 *   · the order total is the sum of its lines (no header arithmetic);
 *   · an order may only be raised against an orderable supplier (active, not
 *     blacklisted) — the check is one call, used by both order and receipt;
 *   · approval is a separate permission from creation and is recorded with the
 *     actor and timestamp (maker/checker at document level; the shared approval
 *     engine is wired at the same time as the bill chain);
 *   · once anything has been received, the order's lines and supplier are
 *     frozen — quantities may not be quietly reduced below what arrived.
 */
class PurchaseOrderService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{supplier_id:int,warehouse_id:int,branch_id?:int,order_date:string,expected_date?:?string,
     *               reference?:?string,payment_terms?:?string,notes?:?string,
     *               lines:array<int, array{product_id?:?int,description?:string,qty_ordered:float,unit_price:float,discount?:float,tax_rate?:float}>}  $data
     */
    public function create(array $data, ?int $actorId = null): PurchaseOrder
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for purchase orders.');

        $supplier = Supplier::query()->whereKey($data['supplier_id'] ?? 0)->firstOrFail();
        $supplier->assertOrderable();

        $warehouse = Warehouse::query()->whereKey($data['warehouse_id'] ?? 0)->firstOrFail();
        $branchId = $data['branch_id'] ?? $warehouse->branch_id ?? $this->context->branchId();

        $lines = $data['lines'] ?? [];

        if ($lines === []) {
            throw new RuntimeException('A purchase order needs at least one line.');
        }

        return DB::transaction(function () use ($data, $lines, $supplier, $warehouse, $branchId, $companyId, $actorId) {
            $order = PurchaseOrder::create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'warehouse_id' => $warehouse->id,
                'supplier_id' => $supplier->id,
                'code' => $data['code'] ?? $this->nextCode($companyId),
                'reference' => $data['reference'] ?? null,
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'status' => 'draft',
                'payment_terms' => $data['payment_terms'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach (array_values($lines) as $index => $line) {
                $order->lines()->create($this->linePayload($line, $index));
            }

            $this->recalculate($order);

            $this->audit->record([
                'action' => 'purchase.order_created',
                'entity_type' => 'purchase_order',
                'entity_id' => $order->id,
                'branch_id' => $order->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $order->code,
                    'supplier' => $supplier->name,
                    'total' => (float) $order->total,
                    'lines' => count($lines),
                ],
            ]);

            return $order->refresh()->load('lines');
        });
    }

    /** Recompute line money + header totals from the lines themselves. */
    public function recalculate(PurchaseOrder $order): PurchaseOrder
    {
        $order->loadMissing('lines');

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($order->lines as $line) {
            $net = round((float) $line->qty_ordered * (float) $line->unit_price, 4);
            $discount = min(round((float) $line->discount, 4), $net);
            $taxable = $net - $discount;
            $tax = round($taxable * ((float) $line->tax_rate / 100), 4);
            $total = round($taxable + $tax, 4);

            $line->forceFill([
                'discount' => $discount,
                'line_total' => $total,
            ])->save();

            $subtotal += $net;
            $discountTotal += $discount;
            $taxTotal += $tax;
        }

        $order->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => round($subtotal - $discountTotal + $taxTotal, 4),
        ])->save();

        return $order->refresh();
    }

    public function submit(PurchaseOrder $order, ?int $actorId = null): PurchaseOrder
    {
        if (! in_array($order->status, ['draft'], true)) {
            throw new RuntimeException("Only a draft order can be submitted (this one is {$order->status}).");
        }

        $order->forceFill(['status' => 'pending_approval'])->save();

        $this->audit->record([
            'action' => 'purchase.order_submitted',
            'entity_type' => 'purchase_order',
            'entity_id' => $order->id,
            'branch_id' => $order->branch_id,
            'actor_id' => $actorId,
            'after' => ['code' => $order->code],
        ]);

        return $order;
    }

    public function approve(PurchaseOrder $order, ?int $actorId = null): PurchaseOrder
    {
        if ($order->status !== 'pending_approval') {
            throw new RuntimeException("Only a submitted order can be approved (this one is {$order->status}).");
        }

        if ($actorId !== null && (int) $order->created_by === $actorId) {
            throw new RuntimeException('The person who raised the order cannot approve it.');
        }

        $order->forceFill([
            'status' => 'approved',
            'approved_by' => $actorId,
            'approved_at' => now(),
        ])->save();

        $this->audit->record([
            'action' => 'purchase.order_approved',
            'entity_type' => 'purchase_order',
            'entity_id' => $order->id,
            'branch_id' => $order->branch_id,
            'actor_id' => $actorId,
            'after' => ['code' => $order->code, 'total' => (float) $order->total],
        ]);

        return $order;
    }

    public function cancel(PurchaseOrder $order, string $reason, ?int $actorId = null): PurchaseOrder
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Cancelling a purchase order requires a reason.');
        }

        if (in_array($order->status, ['received', 'cancelled'], true)) {
            throw new RuntimeException("A {$order->status} order cannot be cancelled.");
        }

        if ((float) $order->lines()->sum('qty_received') > 0) {
            throw new RuntimeException('Something has already been received against this order — return it instead of cancelling.');
        }

        $order->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();

        $this->audit->record([
            'action' => 'purchase.order_cancelled',
            'entity_type' => 'purchase_order',
            'entity_id' => $order->id,
            'branch_id' => $order->branch_id,
            'actor_id' => $actorId,
            'reason' => $reason,
            'after' => ['code' => $order->code],
        ]);

        return $order;
    }

    /* ------------------------------------------------------------------ */

    /** @param array<string, mixed> $line */
    protected function linePayload(array $line, int $sortOrder): array
    {
        $description = trim((string) ($line['description'] ?? ''));

        if ($description === '') {
            throw new RuntimeException('Every purchase line needs a description.');
        }

        $qty = (float) ($line['qty_ordered'] ?? 0);

        if ($qty <= 0) {
            throw new RuntimeException("Line \"{$description}\" needs a quantity greater than zero.");
        }

        return [
            'product_id' => $line['product_id'] ?? null,
            'description' => $description,
            'qty_ordered' => $qty,
            'qty_received' => 0,
            'unit_price' => (float) ($line['unit_price'] ?? 0),
            'discount' => (float) ($line['discount'] ?? 0),
            'tax_rate' => (float) ($line['tax_rate'] ?? 0),
            'line_total' => 0,
            'sort_order' => $line['sort_order'] ?? $sortOrder,
        ];
    }

    /** PO-00001 per company, gaps allowed (cancelled documents keep their code). */
    public function nextCode(int $companyId): string
    {
        $last = PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('code');

        $next = $last !== null && preg_match('/PO-(\d+)$/', (string) $last, $m) === 1 ? ((int) $m[1]) + 1 : 1;

        return sprintf('PO-%05d', $next);
    }
}
