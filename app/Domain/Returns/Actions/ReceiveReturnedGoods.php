<?php

namespace App\Domain\Returns\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\OrderStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ReceiveReturnedGoods (07-05). Qty guard vs approved/requested lines;
 * posts SALES_RETURN inbound (on_hand by default; quarantine optional via
 * state in payload). History never rewritten — new inbound movement only.
 * The linked order moves to returned (02-26) when the machine allows it.
 */
class ReceiveReturnedGoods
{
    public function __construct(
        protected StockLedgerService $ledger,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected OrderStateMachine $stateMachine,
    ) {}

    /**
     * @param  array{state?: string}  $payload
     */
    public function handle(SalesReturn $salesReturn, array $payload, Request $request): SalesReturn
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($salesReturn, $payload, $request) {
            $fresh = SalesReturn::query()->whereKey($salesReturn->id)->lockForUpdate()->firstOrFail();

            if (! in_array($fresh->status, ['approved', 'requested'], true)) {
                throw new RuntimeException("Return {$fresh->return_no} cannot be received from status [{$fresh->status}].");
            }

            if ($fresh->warehouse_id === null) {
                throw new RuntimeException('Return has no warehouse to receive stock into.');
            }

            $state = $payload['state'] ?? StockMovement::STATE_ON_HAND;
            if (! in_array($state, [
                StockMovement::STATE_ON_HAND,
                StockMovement::STATE_QUARANTINED,
                StockMovement::STATE_DAMAGED,
            ], true)) {
                throw new RuntimeException("Invalid receive state [{$state}].");
            }

            foreach ($fresh->lines as $line) {
                if ($line->product_id === null || (float) $line->qty <= 0) {
                    continue;
                }

                $product = Product::query()->find($line->product_id);
                if ($product === null || ! $product->is_stocked) {
                    continue;
                }

                $this->ledger->post([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $fresh->warehouse_id,
                    'movement_type' => StockMovement::TYPE_SALES_RETURN,
                    'qty' => (float) $line->qty,
                    'state' => $state,
                    'source_type' => 'sales_return',
                    'source_id' => $fresh->id,
                    'source_event' => 'return_received',
                    'idempotency_key' => sprintf('ret-in:%d:%d', $fresh->id, $line->product_id),
                    'narration' => "Receive against {$fresh->return_no}",
                ], $request->user());
            }

            $fresh->status = 'received';
            $fresh->save();

            $order = null;
            $orderChanged = false;
            if ($fresh->invoice?->sales_order_id !== null) {
                $order = SalesOrder::query()->whereKey($fresh->invoice->sales_order_id)->lockForUpdate()->first();
                if ($order !== null && $this->stateMachine->canTransition($order->status, 'returned')) {
                    $order = $this->stateMachine->transition($order, 'returned');
                    $orderChanged = true;
                }
            }

            $this->audit->record([
                'action' => 'returns.received',
                'entity_type' => 'sales_return',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'return_no' => $fresh->return_no,
                    'status' => 'received',
                    'state' => $state,
                    'order_no' => $order?->order_no,
                    'order_status' => $order?->status,
                    'order_status_changed' => $orderChanged,
                ],
            ]);

            return $fresh;
        });
    }
}
