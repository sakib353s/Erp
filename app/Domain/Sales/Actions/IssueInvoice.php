<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Delivery\Shipment;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\ReservationService;
use App\Domain\Sales\Services\WarrantyService;
use App\Domain\Sales\Warranty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IssueInvoice: GL Dr AR / Cr Sales (+Tax) via posting_rules;
 * stock SALES_OUT + COGS when warehouse present; reservation consume.
 */
class IssueInvoice
{
    public function __construct(
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected StockLedgerService $ledger,
        protected ReservationService $reservations,
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected WarrantyService $warranties,
    ) {}

    public function handle(Invoice $invoice, Request $request): Invoice
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        if (! in_array($invoice->status, ['draft', 'pending'], true)) {
            throw new RuntimeException("Invoice {$invoice->invoice_no} cannot be issued from status [{$invoice->status}].");
        }

        if ($invoice->posting_state === 'posted') {
            return $invoice;
        }

        return DB::transaction(function () use ($invoice, $companyId, $request) {
            $fresh = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $resolved = $this->rules->resolve('sales_invoice_issued', 'invoice');
            $byRole = [];
            foreach ($resolved as $r) {
                $byRole[$r['role']] = $r;
            }

            $grand = (float) $fresh->grand_total;
            $tax = (float) $fresh->tax;
            $salesCredit = round($grand - $tax, 4);

            $lines = [];
            if (isset($byRole['ar']) && $grand > 0) {
                $lines[] = [
                    'account_id' => $byRole['ar']['account']->id,
                    'dc' => 'debit',
                    'amount' => $grand,
                    'party_type' => $fresh->customer_id ? 'customer' : null,
                    'party_id' => $fresh->customer_id,
                ];
            }
            if (isset($byRole['sales']) && $salesCredit > 0) {
                $lines[] = [
                    'account_id' => $byRole['sales']['account']->id,
                    'dc' => 'credit',
                    'amount' => $salesCredit,
                ];
            }
            if (isset($byRole['tax_payable']) && $tax > 0) {
                $lines[] = [
                    'account_id' => $byRole['tax_payable']['account']->id,
                    'dc' => 'credit',
                    'amount' => $tax,
                ];
            }

            if (count($lines) >= 2) {
                $entry = $this->posting->post([
                    'entry_date' => $fresh->invoice_date->toDateString(),
                    'description' => "Invoice {$fresh->invoice_no}",
                    'journal_type' => 'sales',
                    'source_type' => 'invoice',
                    'source_id' => $fresh->id,
                    'source_event' => 'sales_invoice_issued',
                    'branch_id' => $fresh->branch_id,
                    'lines' => $lines,
                ], $request->user());
                $fresh->journal_entry_id = $entry->id;
            }

            // Standard orders issue against warehouse + reservation; POS invoices
            // issue when warehouse is set even without a sales order (counter sale).
            $stockable = $fresh->warehouse_id !== null
                && ($fresh->sales_order_id !== null || $fresh->invoice_type === 'pos');

            if ($stockable) {
                // Goods already dispatched for this order sit in in_transit:
                // their cost left on_hand at DispatchShipment, so issue clears
                // that transit (never a second SALES_OUT) and only the rest
                // issues fresh — stock is deducted exactly once either way.
                $transitQueue = $fresh->sales_order_id !== null
                    ? $this->transitQueueFor($fresh)
                    : [];

                foreach ($fresh->lines as $line) {
                    if ($line->product_id === null) {
                        continue;
                    }

                    $product = Product::query()->find($line->product_id);
                    if ($product === null || ! $product->is_stocked) {
                        continue;
                    }

                    $lineQty = (float) $line->qty;
                    [$clearQty, $clearCost] = $this->takeTransit($transitQueue, (int) $line->product_id, $lineQty);
                    $outQty = round($lineQty - $clearQty, 4);

                    $cogs = round($clearCost, 4);

                    if ($clearQty > 0) {
                        $this->ledger->post([
                            'product_id' => $line->product_id,
                            'warehouse_id' => $fresh->warehouse_id,
                            'movement_type' => StockMovement::TYPE_TRANSIT_CLEAR,
                            'qty' => $clearQty,
                            'unit_cost' => $clearCost / $clearQty,
                            'source_type' => 'invoice',
                            'source_id' => $fresh->id,
                            'source_event' => 'invoice_issued',
                            'idempotency_key' => sprintf('inv-trclear:%d:%d', $fresh->id, $line->product_id),
                            'narration' => "Clear dispatched transit against {$fresh->invoice_no}",
                        ], $request->user());
                    }

                    if ($outQty > 0) {
                        $movement = $this->ledger->post([
                            'product_id' => $line->product_id,
                            'warehouse_id' => $fresh->warehouse_id,
                            'movement_type' => StockMovement::TYPE_SALES_OUT,
                            'qty' => $outQty,
                            'source_type' => 'invoice',
                            'source_id' => $fresh->id,
                            'source_event' => 'invoice_issued',
                            'idempotency_key' => sprintf('inv-out:%d:%d', $fresh->id, $line->product_id),
                            'narration' => "Issue against {$fresh->invoice_no}",
                        ], $request->user());

                        $cogs += round((float) ($movement->unit_cost ?? 0) * $outQty, 4);
                    }

                    if ($cogs > 0) {
                        try {
                            $cogsResolved = $this->rules->resolve('sales_cost');
                            $cogsByRole = [];
                            foreach ($cogsResolved as $r) {
                                $cogsByRole[$r['role']] = $r;
                            }
                            if (isset($cogsByRole['cogs'], $cogsByRole['inventory'])) {
                                $this->posting->post([
                                    'entry_date' => $fresh->invoice_date->toDateString(),
                                    'description' => "COGS {$fresh->invoice_no}",
                                    'journal_type' => 'sales',
                                    'source_type' => 'invoice',
                                    'source_id' => $fresh->id,
                                    'source_event' => 'sales_cost',
                                    'branch_id' => $fresh->branch_id,
                                    'lines' => [
                                        ['account_id' => $cogsByRole['cogs']['account']->id, 'dc' => 'debit', 'amount' => $cogs],
                                        ['account_id' => $cogsByRole['inventory']['account']->id, 'dc' => 'credit', 'amount' => $cogs],
                                    ],
                                ], $request->user());
                            }
                        } catch (RuntimeException) {
                            // sales_cost rule optional in minimal setups
                        }
                    }
                }

                if ($fresh->sales_order_id !== null) {
                    $this->reservations->consume($companyId, 'sales_order', $fresh->sales_order_id);

                    SalesOrder::query()->whereKey($fresh->sales_order_id)->update(['status' => 'completed']);
                }
            }

            $fresh->status = 'issued';
            $fresh->posting_state = 'posted';
            $fresh->save();

            // §16-16: for goods no delivered challan carried — a counter sale, a
            // service invoice — the invoice itself is the moment the customer
            // received them, so the cover starts on the invoice date. Where the
            // goods already went out on a challan the warranty exists and this
            // writes nothing (WarrantyService refuses to promise twice).
            $activated = $this->warranties->activateForInvoice($fresh, $request->user());

            $this->audit->record([
                'action' => 'sales.invoice_issued',
                'entity_type' => 'invoice',
                'entity_id' => $fresh->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'invoice_no' => $fresh->invoice_no,
                    'status' => 'issued',
                    'posting_state' => 'posted',
                    'printed_title' => $fresh->printed_title,
                    'journal_entry_id' => $fresh->journal_entry_id,
                    'warranties_activated' => array_map(fn (Warranty $w) => $w->code, $activated),
                ],
            ]);

            return $fresh;
        });
    }

    /**
     * Per-product FIFO queue of in-transit qty dispatched for this
     * order that no invoice has cleared yet:
     * [product_id => list<array{qty: float, unit_cost: float}>].
     *
     * @return array<int, list<array{qty: float, unit_cost: float}>>
     */
    protected function transitQueueFor(Invoice $invoice): array
    {
        $shipmentIds = Shipment::query()
            ->where('sales_order_id', $invoice->sales_order_id)
            ->pluck('id');

        if ($shipmentIds->isEmpty()) {
            return [];
        }

        $queue = [];
        $movements = StockMovement::query()
            ->where('company_id', $invoice->company_id)
            ->where('movement_type', StockMovement::TYPE_TRANSIT_OUT)
            ->where('source_type', 'shipment')
            ->whereIn('source_id', $shipmentIds)
            ->orderBy('id')
            ->get();

        foreach ($movements as $movement) {
            $queue[(int) $movement->product_id][] = [
                'qty' => abs((float) $movement->qty_signed),
                'unit_cost' => (float) $movement->unit_cost,
            ];
        }

        if ($queue === []) {
            return [];
        }

        $cleared = [];
        $clears = StockMovement::query()
            ->where('company_id', $invoice->company_id)
            ->where('movement_type', StockMovement::TYPE_TRANSIT_CLEAR)
            ->where('source_type', 'invoice')
            ->whereIn(
                'source_id',
                Invoice::query()->where('sales_order_id', $invoice->sales_order_id)->select('id'),
            )
            ->get();

        foreach ($clears as $clear) {
            $pid = (int) $clear->product_id;
            $cleared[$pid] = ($cleared[$pid] ?? 0.0) + abs((float) $clear->qty_signed);
        }

        foreach ($cleared as $pid => $qty) {
            if (! isset($queue[$pid])) {
                continue;
            }

            // Rebuild the queue entries after the take-off.
            $remaining = [];
            $left = $qty;
            foreach ($queue[$pid] as $entry) {
                if ($left <= 1e-9) {
                    $remaining[] = $entry;

                    continue;
                }
                $taken = min($left, $entry['qty']);
                $entry['qty'] -= $taken;
                $left -= $taken;
                if ($entry['qty'] > 1e-9) {
                    $remaining[] = $entry;
                }
            }
            $queue[$pid] = $remaining;
            if ($queue[$pid] === []) {
                unset($queue[$pid]);
            }
        }

        return $queue;
    }

    /**
     * Take up to $qty from a product's transit queue (FIFO).
     *
     * @param  array<int, list<array{qty: float, unit_cost: float}>>  $queue
     * @return array{0: float, 1: float} [cleared qty, cost of the cleared qty]
     */
    protected function takeTransit(array &$queue, int $productId, float $qty): array
    {
        if ($qty <= 0 || ! isset($queue[$productId])) {
            return [0.0, 0.0];
        }

        $taken = 0.0;
        $cost = 0.0;

        foreach ($queue[$productId] as $index => $entry) {
            if ($taken >= $qty - 1e-9) {
                break;
            }

            $portion = min($qty - $taken, $entry['qty']);
            if ($portion <= 1e-9) {
                continue;
            }

            $cost += $portion * $entry['unit_cost'];
            $taken += $portion;
            $queue[$productId][$index]['qty'] -= $portion;
        }

        $queue[$productId] = array_values(array_filter(
            $queue[$productId],
            fn (array $entry) => $entry['qty'] > 1e-9,
        ));

        if ($queue[$productId] === []) {
            unset($queue[$productId]);
        }

        return [round($taken, 4), $cost];
    }
}
