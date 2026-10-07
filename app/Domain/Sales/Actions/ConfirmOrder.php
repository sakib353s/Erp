<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderApprovalGate;
use App\Domain\Sales\Services\ReservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ConfirmOrder: pending → confirmed + stock reservation (not issue).
 *
 * Workflow (02-04): when a workflow definition matches sales_order/confirm,
 * the order is submitted for approval and stays pending (no reservation);
 * the effect is applied on the confirm attempt that follows an approved
 * request. No definition (bypass) → confirm directly.
 */
class ConfirmOrder
{
    public function __construct(
        protected ReservationService $reservations,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderApprovalGate $approvalGate,
    ) {}

    public function handle(SalesOrder $order, Request $request): SalesOrder
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (! in_array($order->status, ['pending', 'confirmed'], true)) {
            throw new RuntimeException("Order {$order->order_no} cannot be confirmed from status [{$order->status}].");
        }

        return DB::transaction(function () use ($order, $companyId, $request) {
            $fresh = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === 'confirmed' && $fresh->stock_reserved) {
                return $fresh->load('lines');
            }

            if ($fresh->status === 'pending'
                && $this->approvalGate->pass($fresh, 'confirm', $request) === OrderApprovalGate::PENDING) {
                return $fresh->load('lines');
            }

            $warehouseId = $fresh->warehouse_id
                ?? Warehouse::query()
                    ->where('company_id', $companyId)
                    ->where('is_default', true)
                    ->value('id');

            if ($warehouseId === null) {
                throw new RuntimeException('No warehouse available to reserve stock for this order.');
            }

            // Layaway orders (02-41) reserve at deposit time — never
            // stack a second reservation on confirm.
            if (! $fresh->stock_reserved) {
                $reserveLines = $fresh->lines->map(fn ($l) => [
                    'product_id' => $l->product_id,
                    'qty' => (float) $l->qty,
                ])->all();

                $this->reservations->reserve(
                    $companyId,
                    (int) $warehouseId,
                    'sales_order',
                    $fresh->id,
                    $reserveLines,
                );
            }

            $fresh->status = 'confirmed';
            $fresh->stock_reserved = true;
            $fresh->warehouse_id = $warehouseId;
            $fresh->save();

            $this->audit->record([
                'action' => 'sales.order_confirmed',
                'entity_type' => 'sales_order',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'order_no' => $fresh->order_no,
                    'status' => 'confirmed',
                    'stock_reserved' => true,
                ],
            ]);

            return $fresh->load('lines');
        });
    }
}
