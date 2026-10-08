<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\Services\ValuationService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Settings\Services\SettingService;
use App\Domain\Inventory\StockTransfer;
use App\Domain\Inventory\StockTransferLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transfer lifecycle (04-27…04-30, approval in 04-28):
 *  - CreateStockTransfer → draft document (no stock yet), or `pending_approval`
 *    when it is worth at least `inventory.transfer_approval_above`
 *  - ApproveTransfer / RejectTransfer → clears or refuses the gate (§04-28)
 *  - DispatchTransfer → TRANSIT_OUT at origin (draft only, so in-transit always
 *    means approved)
 *  - ReceiveTransfer → TRANSIT_IN at destination; short receive opens discrepancy
 *
 * A held transfer is not a draft with a label on it: `dispatch()` refuses
 * anything that is not a draft, so the gate cannot be walked around by calling
 * the next step directly.
 */
class StockTransferService
{
    /** 0 (or less) means every transfer dispatches as it always has. */
    public const SETTING_GROUP = 'inventory';

    public const SETTING_KEY = 'transfer_approval_above';

    public function __construct(
        protected StockLedgerService $ledger,
        protected ValuationService $valuation,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected SettingService $settings,
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

            $prepared = [];
            $value = 0.0;

            // The source warehouse, once: a line with no stated cost is valued at
            // what the goods cost where they are leaving from.
            $from = Warehouse::withoutGlobalScope(\App\Domain\Foundation\Concerns\BranchScope::class)
                ->where('company_id', $companyId)
                ->whereKey($fromId)
                ->firstOrFail();

            foreach ($lines as $index => $line) {
                $qty = (float) ($line['qty_sent'] ?? 0);

                if ($qty <= 0) {
                    throw new RuntimeException('Transfer quantities must be greater than zero.');
                }

                $product = Product::query()->findOrFail((int) $line['product_id']);
                $given = $line['unit_cost'] ?? null;

                // The figure the threshold judges: what the line says it costs,
                // or what the goods cost at the source. Dispatch finally values
                // the outbound leg by the layers it consumes.
                $cost = ($given === null || $given === '' || (float) $given <= 0)
                    ? $this->valuation->unitCost($product, $from)
                    : (float) $given;

                $value += $qty * $cost;

                $prepared[] = [
                    'product_id' => $product->id,
                    'qty_sent' => number_format($qty, 4, '.', ''),
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'line_no' => $index + 1,
                ];
            }

            $value = round($value, 4);
            $threshold = $this->approvalThreshold();
            $pending = $threshold > 0 && $value >= $threshold;

            $transfer = StockTransfer::create([
                'company_id' => $companyId,
                'branch_id' => $request->user()->default_branch_id,
                'from_warehouse_id' => $fromId,
                'to_warehouse_id' => $toId,
                'transfer_no' => $transferNo,
                'transfer_date' => $payload['transfer_date'] ?? now()->toDateString(),
                'status' => $pending ? StockTransfer::STATUS_PENDING : StockTransfer::STATUS_DRAFT,
                'narration' => $payload['narration'] ?? null,
                'total_value' => $value,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($prepared as $line) {
                $lineNo++;
                StockTransferLine::create($line + ['stock_transfer_id' => $transfer->id]);
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
                    'total_value' => $value,
                    'awaiting_approval' => $pending,
                ],
            ]);

            if ($pending) {
                $this->audit->record([
                    'action' => 'inventory.transfer_submitted',
                    'entity_type' => 'stock_transfer',
                    'entity_id' => $transfer->id,
                    'branch_id' => $transfer->branch_id,
                    'actor_id' => $request->user()->id,
                    'after' => [
                        'transfer_no' => $transferNo,
                        'total_value' => $value,
                        'line_count' => $lineNo,
                    ],
                ]);
            }

            return $transfer->load('lines');
        });
    }

    /** The value above which a transfer needs a second pair of eyes. 0 = never. */
    public function approvalThreshold(): float
    {
        return max(0.0, (float) $this->settings->get(self::SETTING_GROUP, self::SETTING_KEY, 0));
    }

    /**
     * Approve a held transfer: it becomes a draft, and only then can it be
     * dispatched. Nothing has moved at this point and nothing moves now — the
     * approval opens the door, the dispatch walks through it.
     */
    public function approve(StockTransfer $transfer, User $actor, ?string $note = null): StockTransfer
    {
        $this->assertApprovable($transfer, $actor);

        $transfer->forceFill([
            'status' => StockTransfer::STATUS_DRAFT,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'approval_note' => $note,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.transfer_approved',
            'entity_type' => 'stock_transfer',
            'entity_id' => $transfer->id,
            'branch_id' => $transfer->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'transfer_no' => $transfer->transfer_no,
                'total_value' => (string) $transfer->total_value,
                'note' => $note,
            ],
        ]);

        return $transfer->refresh()->load('lines');
    }

    /** Refuse a held transfer. It can never be dispatched afterwards. */
    public function reject(StockTransfer $transfer, User $actor, string $note): StockTransfer
    {
        $this->assertApprovable($transfer, $actor);

        $note = trim($note);

        if ($note === '') {
            throw new RuntimeException('A rejection needs a reason — the person who raised it has to know what to fix.');
        }

        $transfer->forceFill([
            'status' => StockTransfer::STATUS_REJECTED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
            'approval_note' => $note,
        ])->save();

        $this->audit->record([
            'action' => 'inventory.transfer_rejected',
            'entity_type' => 'stock_transfer',
            'entity_id' => $transfer->id,
            'branch_id' => $transfer->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'transfer_no' => $transfer->transfer_no,
                'total_value' => (string) $transfer->total_value,
                'note' => $note,
            ],
        ]);

        return $transfer->refresh()->load('lines');
    }

    protected function assertApprovable(StockTransfer $transfer, User $actor): void
    {
        if ($this->context->companyId() !== null
            && (int) $transfer->company_id !== (int) $this->context->companyId()) {
            throw new RuntimeException('That transfer belongs to another company.');
        }

        if (! $transfer->isPending()) {
            throw new RuntimeException('Only a transfer waiting for approval can be decided.');
        }

        if ((int) $transfer->created_by === (int) $actor->id) {
            throw new RuntimeException('A transfer cannot be approved or rejected by the person who raised it.');
        }
    }

    public function dispatch(StockTransfer $transfer, Request $request): StockTransfer
    {
        if ($transfer->status !== StockTransfer::STATUS_DRAFT) {
            throw new RuntimeException($transfer->isPending()
                ? 'This transfer is still waiting for approval — it cannot leave the warehouse yet.'
                : 'Only draft transfers can be dispatched.');
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

                    // Arrive at destination, ON THE SHELF. The in-transit
                    // compartment belongs to the sending warehouse: it is credited
                    // on dispatch and closed by the TRANSIT_CLEAR above. The
                    // receiving end has nothing in transit to give back, so it is
                    // posted straight into `on_hand` — leaving the type's default
                    // in-transit state here would drive the destination's transit
                    // column negative for goods it never held.
                    $this->ledger->post([
                        'product_id' => $line->product_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'movement_type' => StockMovement::TYPE_TRANSIT_IN,
                        'state' => StockMovement::STATE_ON_HAND,
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
