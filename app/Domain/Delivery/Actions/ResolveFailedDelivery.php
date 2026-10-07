<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\FailedDelivery;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\SalesOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ResolveFailedDelivery (02-96): the three recovery states for a
 * failed delivery —
 *
 *  - retry: the courier takes the same shipment out again
 *    (delivery_failed → out_for_delivery, no stock movement — the
 *    goods never came back);
 *  - return: goods come back to the warehouse → STK reverse of this
 *    shipment's own dispatch (TRANSIT_IN exactly mirrors the posted
 *    TRANSIT_OUT lines; nothing is reversed when dispatch posted
 *    nothing); refused with a truthful reason when the invoice
 *    already issued the stock — that return belongs to the sales
 *    return flow, never a fabricated reversal;
 *  - reship: return + close the failed shipment (cancelled, so its
 *    lines free up the ordered remainder) + create the replacement
 *    shipment through CreateShipment.
 *
 * The order status never moves here: the state machine has no
 * backward hops, and a resolved record is final — a later failure of
 * the retried/replacement shipment opens the next attempt row.
 */
class ResolveFailedDelivery
{
    /** @var array<int, string> */
    public const ACTIONS = ['retry', 'return', 'reship'];

    public function __construct(
        protected TenantContext $context,
        protected StockLedgerService $ledger,
        protected CreateShipment $createShipment,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param  array{courier_id?: int|string|null}  $payload
     */
    public function handle(FailedDelivery $record, string $action, array $payload, Request $request): FailedDelivery
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new RuntimeException(sprintf('Unknown failed-delivery action [%s].', $action));
        }

        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if ((int) $record->company_id !== $companyId) {
            abort(404);
        }

        return DB::transaction(function () use ($record, $action, $payload, $request) {
            $fresh = FailedDelivery::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== FailedDelivery::STATUS_OPEN) {
                throw new RuntimeException(sprintf(
                    'Failed delivery #%d is already resolved as [%s].',
                    $fresh->id,
                    $fresh->status,
                ));
            }

            $shipment = Shipment::query()->whereKey($fresh->shipment_id)->lockForUpdate()->firstOrFail();

            if ($shipment->status !== Shipment::STATUS_DELIVERY_FAILED) {
                throw new RuntimeException(sprintf(
                    'Shipment #%d is not awaiting recovery (status [%s]).',
                    $shipment->id,
                    $shipment->status,
                ));
            }

            $order = $fresh->sales_order_id !== null
                ? SalesOrder::query()->whereKey($fresh->sales_order_id)->first()
                : null;

            $stockReversed = 0;
            $stockReason = null;
            $newShipmentId = null;

            if ($action === 'retry') {
                $shipment->status = Shipment::STATUS_OUT_FOR_DELIVERY;
                $shipment->save();
            } else {
                [$stockReversed, $stockReason] = $this->reverseDispatchStock($shipment, $order, $request);

                if ($action === 'reship') {
                    if ($order === null) {
                        throw new RuntimeException('Shipment has no order to reship.');
                    }

                    $shipment->status = Shipment::STATUS_CANCELLED;
                    $shipment->save();

                    $replacement = $this->createShipment->handle($order, [
                        'courier_id' => $payload['courier_id'] ?? $shipment->courier_id,
                        'lines' => $shipment->lines->map(fn ($line) => [
                            'product_id' => $line->product_id,
                            'qty' => $line->qty,
                        ])->all(),
                    ], $request);

                    $newShipmentId = $replacement->id;
                }
            }

            $fresh->status = match ($action) {
                'retry' => FailedDelivery::STATUS_RETRIED,
                'return' => FailedDelivery::STATUS_RETURNED,
                'reship' => FailedDelivery::STATUS_RESHIPPED,
            };
            $fresh->resolved_at = now();
            $fresh->resolved_by = $request->user()?->id;
            $fresh->save();

            $this->audit->record([
                'action' => sprintf('sales.failed_delivery_%s', [
                    'retry' => 'retried',
                    'return' => 'returned',
                    'reship' => 'reshipped',
                ][$action]),
                'entity_type' => 'failed_delivery',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()?->id,
                'after' => [
                    'shipment_id' => $shipment->id,
                    'order_no' => $order?->order_no,
                    'attempt_no' => $fresh->attempt_no,
                    'action' => $action,
                    'shipment_status' => $shipment->fresh()->status,
                    'stock_reversed' => $stockReversed,
                    'stock_reason' => $stockReason,
                    'new_shipment_id' => $newShipmentId,
                ],
            ]);

            return $fresh;
        });
    }

    /**
     * Reverse this shipment's own dispatch (TRANSIT_IN mirrors the
     * posted TRANSIT_OUT lines back into the warehouse).
     *
     * @return array{0: int, 1: string|null} posted lines, truthful reason
     */
    protected function reverseDispatchStock(Shipment $shipment, ?SalesOrder $order, Request $request): array
    {
        if ($order === null) {
            return [0, 'Shipment has no order to restock against.'];
        }

        if ($order->warehouse_id === null) {
            return [0, 'Order has no warehouse configured.'];
        }

        $issued = $order->invoices()
            ->where('status', 'issued')
            ->exists();

        if ($issued) {
            throw new RuntimeException(
                'Invoice already issued stock for this order — record the return through the sales return flow.'
            );
        }

        $dispatched = StockMovement::query()
            ->where('company_id', $order->company_id)
            ->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)
            ->where('source_type', 'shipment')
            ->where('source_id', $shipment->id)
            ->get();

        if ($dispatched->isEmpty()) {
            return [0, 'Dispatch posted no stock movement for this shipment.'];
        }

        $posted = 0;
        foreach ($dispatched->groupBy('product_id') as $productId => $movements) {
            $product = Product::query()->find((int) $productId);

            if ($product === null || ! $product->is_stocked || ! $product->is_active) {
                continue;
            }

            $qty = round(
                $movements->sum(fn (StockMovement $movement) => abs((float) $movement->qty_signed)),
                4,
            );

            if ($qty <= 0) {
                continue;
            }

            $this->ledger->post([
                'product_id' => $product->id,
                'warehouse_id' => $order->warehouse_id,
                'movement_type' => StockMovement::TYPE_TRANSIT_IN,
                'qty' => $qty,
                'source_type' => 'shipment',
                'source_id' => $shipment->id,
                'source_event' => 'shipment_returned',
                'idempotency_key' => sprintf('ship-ret:%d:%d', $shipment->id, $product->id),
                'narration' => 'Return failed delivery for '.$order->order_no,
            ], $request->user());

            $posted++;
        }

        return [$posted, null];
    }
}
