<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\Services\ValuationService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockTransfer;
use App\Domain\Inventory\StockTransferLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transfer lifecycle (04-27…04-30):
 *  - CreateStockTransfer → draft document (no stock yet)
 *  - DispatchTransfer → TRANSIT_OUT at origin
 *  - ReceiveTransfer → TRANSIT_IN at destination; short receive opens discrepancy
 */
class StockTransferService
{
    public function __construct(
        protected StockLedgerService $ledger,
        protected ValuationService $valuation,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   from_warehouse_id: int,
     *   to_warehouse_id: int,
     *   transfer_date?: string,
     *   narration?: string|null,
     *   lines: array<int, array{product_id: int, qty_sent: float, unit_cost?: float|null}>,
     * } $payload
     */
    public function create(array $payload, Request $request): StockTransfer
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $fromId = (int) $payload['from_warehouse_id'];
        $toId = (int) $payload['to_warehouse_id'];
        $lines = $payload['lines'] ?? [];

        if ($fromId === $toId) {
            throw new RuntimeException('Source and destination warehouses must differ.');
        }

        if ($lines === []) {
            throw new RuntimeException('Transfer requires at least one line.');
        }

        return DB::transaction(function () use ($payload, $lines, $companyId, $fromId, $toId, $request) {
            $docType = DocumentType::query()->where('code', 'stock_transfer')->first()
                ?? abort(500, 'stock_transfer document type is not seeded.');

            $transferNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'transfer_no' => $transferNo,
                'transfer_date' => $payload['transfer_date'] ?? now()->toDateString(),
                'status' => StockTransfer::STATUS_DRAFT,
                'narration' => $payload['narration'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($lines as $line) {
                $lineNo++;
                $qty = (float) ($line['qty_sent'] ?? 0);
                if ($qty <= 0) {
                    throw new RuntimeException('Transfer quantities must be greater than zero.');
                }

                StockTransferLine::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => (int) $line['product_id'],
                    'qty_sent' => number_format($qty, 4, '.', ''),
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'line_no' => $lineNo,
                ]);
            }

            $this->audit->record([
                'action' => 'inventory.transfer_created',
                'entity_type' => 'stock_transfer',
                'entity_id' => $transfer->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'transfer_no' => $transferNo,
                    'from_warehouse_id' => $fromId,
                    'to_warehouse_id' => $toId,
                    'line_count' => $lineNo,
                ],
            ]);

            return $transfer->load('lines');
        });
    }

    public function dispatch(StockTransfer $transfer, Request $request): StockTransfer
    {
        if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
            throw new RuntimeException('Only draft transfers can be dispatched.');
        }

        return DB::transaction(function () use ($transfer, $request) {
            foreach ($transfer->lines as $line) {
                $this->ledger->post([
                    'product_id' => $line->product_id,
                    'warehouse_id' => $transfer->from_warehouse_id,
                    'movement_type' => StockMovement::TYPE_TRANSIT_OUT,
                    'qty' => (float) $line->qty_sent,
                    'unit_cost' => $line->unit_cost > 0 ? (float) $line->unit_cost : null,
                    'source_type' => 'stock_transfer',
                    'source_id' => $transfer->id,
                    'source_event' => 'transfer_dispatched',
                    'idempotency_key' => sprintf('trf-out:%d:%d:%d', $transfer->id, $line->line_no, $line->product_id),
                    'narration' => 'Dispatch '.$transfer->transfer_no,
                ], $request->user());
            }

            $transfer->update([
                'status' => StockTransfer::STATUS_DISPATCHED,
                'dispatched_at' => now(),
            ]);

            $this->audit->record([
                'action' => 'inventory.transfer_dispatched',
                'entity_type' => 'stock_transfer',
                'entity_id' => $transfer->id,
                'actor_id' => $request->user()->id,
                'after' => ['status' => StockTransfer::STATUS_DISPATCHED],
            ]);

            return $transfer->refresh()->load('lines');
        });
    }

    /**
     * Receive with per-line qty_received (defaults to qty_sent).
     * Short receive → status discrepancy (never silent loss).
     *
     * @param  array<int, array{product_id: int, qty_received: float}>  $receipts
     */
    public function receive(StockTransfer $transfer, array $receipts, Request $request): StockTransfer
    {
        if ($transfer->status !== StockTransfer::STATUS_DISPATCHED) {
            throw new RuntimeException('Only dispatched transfers can be received.');
        }

        return DB::transaction(function () use ($transfer, $receipts, $request) {
            $hasDiscrepancy = false;
            $receiptMap = [];
            foreach ($receipts as $receipt) {
                $receiptMap[(int) $receipt['product_id']] = (float) ($receipt['qty_received'] ?? 0);
            }

            foreach ($transfer->lines as $line) {
                $qtyReceived = $receiptMap[$line->product_id]
                    ?? (float) $line->qty_sent;
                $qtySent = (float) $line->qty_sent;

                if ($qtyReceived < 0) {
                    throw new RuntimeException('Received quantity cannot be negative.');
                }

                if ($qtyReceived > 0) {
                    // Close origin in-transit (layers already consumed on dispatch).
                    $this->ledger->post([
                        'product_id' => $line->product_id,
                        'warehouse_id' => $transfer->from_warehouse_id,
                        'movement_type' => StockMovement::TYPE_TRANSIT_CLEAR,
                        'qty' => $qtyReceived,
                        'unit_cost' => $line->unit_cost > 0 ? (float) $line->unit_cost : null,
                        'source_type' => 'stock_transfer',
                        'source_id' => $transfer->id,
                        'source_event' => 'transfer_received',
                        'idempotency_key' => sprintf('trf-clr:%d:%d:%d', $transfer->id, $line->line_no, $line->product_id),
                        'narration' => 'Receive '.$transfer->transfer_no.' (clear transit)',
                    ], $request->user());

                    // Arrive at destination.
                    $this->ledger->post([
                        'product_id' => $line->product_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'movement_type' => StockMovement::TYPE_TRANSIT_IN,
                        'qty' => $qtyReceived,
                        'unit_cost' => $line->unit_cost > 0 ? (float) $line->unit_cost : null,
                        'source_type' => 'stock_transfer',
                        'source_id' => $transfer->id,
                        'source_event' => 'transfer_received',
                        'idempotency_key' => sprintf('trf-in:%d:%d:%d', $transfer->id, $line->line_no, $line->product_id),
                        'narration' => 'Receive '.$transfer->transfer_no,
                    ], $request->user());
                }

                $line->qty_received = number_format($qtyReceived, 4, '.', '');
                $line->save();

                if (bccomp(number_format($qtyReceived, 4, '.', ''), number_format($qtySent, 4, '.', ''), 4) !== 0) {
                    $hasDiscrepancy = true;
                }
            }

            $transfer->update([
                'status' => $hasDiscrepancy
                    ? StockTransfer::STATUS_DISCREPANCY
                    : StockTransfer::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            $this->audit->record([
                'action' => $hasDiscrepancy
                    ? 'inventory.transfer_discrepancy'
                    : 'inventory.transfer_received',
                'entity_type' => 'stock_transfer',
                'entity_id' => $transfer->id,
                'actor_id' => $request->user()->id,
                'after' => ['status' => $transfer->status],
                'reason' => $hasDiscrepancy ? 'Received quantity differs from sent' : null,
            ]);

            return $transfer->refresh()->load('lines');
        });
    }
}
