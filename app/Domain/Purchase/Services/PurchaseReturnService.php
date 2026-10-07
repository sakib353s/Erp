<?php

namespace App\Domain\Purchase\Services;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseReturn;
use App\Domain\Purchase\Models\PurchaseReturnLine;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchase returns (§03.9) — goods going back, and the debit note that goes
 * with them.
 *
 * Rules owned here:
 *   · a posted receipt or bill cannot be edited or deleted, so this is the
 *     only honest correction path — and it is a document of its own, not an
 *     edit of one;
 *   · you cannot return more than arrived: when a line points at a receipt
 *     line, the remaining returnable quantity (received − already returned)
 *     is enforced in the service, not by the form;
 *   · approval moves stock out (PURCHASE_RETURN_OUT) for stock-managed
 *     products only, and posts the ledger: Dr Accounts Payable, Cr Inventory
 *     (or Purchases & Services) and Cr Tax Payable for the input tax we
 *     claimed — through posting_rules, never a hardcoded account;
 *   · when the return is tied to a bill, the bill's credit is taken in the
 *     same transaction, so the bill's own balance and the AP control account
 *     keep agreeing (due = total − paid − credited);
 *   · an excess over the bill's balance is refused rather than quietly
 *     creating an unapplied debit note this slice cannot track yet.
 */
class PurchaseReturnService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected JournalPostingService $journal,
        protected PostingRuleResolver $rules,
        protected StockLedgerService $stock,
    ) {}

    /**
     * @param  array{supplier_id:int,branch_id?:int,warehouse_id?:?int,purchase_order_id?:?int,
     *               goods_receipt_id?:?int,purchase_bill_id?:?int,return_date:string,reason:string,
     *               reason_code?:?string,goods_dispatched?:bool,lines:array<int, array{
     *                 product_id?:?int,description?:string,qty:float,unit_cost:float,discount?:float,
     *                 tax_rate?:float,goods_receipt_line_id?:?int,purchase_order_line_id?:?int,
     *                 purchase_bill_line_id?:?int,batch_no?:?string}>}  $data
     */
    public function create(array $data, ?int $actorId = null): PurchaseReturn
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for purchase returns.');

        $supplier = Supplier::query()->whereKey($data['supplier_id'] ?? 0)->firstOrFail();
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            throw new RuntimeException('A purchase return needs a reason — the supplier will ask for one.');
        }

        $lines = array_values(array_filter($data['lines'] ?? [], fn ($line) => (float) ($line['qty'] ?? 0) > 0));

        if ($lines === []) {
            throw new RuntimeException('A purchase return needs at least one line with a quantity.');
        }

        $receipt = isset($data['goods_receipt_id']) && $data['goods_receipt_id'] !== null
            ? GoodsReceipt::query()->with('lines')->findOrFail($data['goods_receipt_id'])
            : null;

        if ($receipt !== null && ! $receipt->isPosted()) {
            throw new RuntimeException("Receipt {$receipt->code} is not posted, so there is nothing to return against it.");
        }

        $bill = isset($data['purchase_bill_id']) && $data['purchase_bill_id'] !== null
            ? PurchaseBill::query()->findOrFail($data['purchase_bill_id'])
            : null;

        return DB::transaction(function () use ($data, $lines, $supplier, $receipt, $bill, $reason, $companyId, $actorId) {
            $return = PurchaseReturn::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? $receipt?->branch_id ?? $this->context->branchId(),
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $data['purchase_order_id'] ?? $receipt?->purchase_order_id,
                'goods_receipt_id' => $receipt?->id,
                'purchase_bill_id' => $bill?->id,
                'warehouse_id' => $data['warehouse_id'] ?? $receipt?->warehouse_id,
                'code' => $data['code'] ?? $this->nextCode($companyId),
                'return_date' => $data['return_date'],
                'reason' => $reason,
                'reason_code' => $data['reason_code'] ?? 'other',
                'goods_dispatched' => (bool) ($data['goods_dispatched'] ?? false),
                'status' => 'draft',
                'posting_state' => 'draft',
                'created_by' => $actorId,
            ]);

            $sort = 0;

            foreach ($lines as $line) {
                $this->assertReturnable($return, $line, $receipt);

                PurchaseReturnLine::create([
                    'purchase_return_id' => $return->id,
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
                    'purchase_bill_line_id' => $line['purchase_bill_line_id'] ?? null,
                    'product_id' => $line['product_id'] ?? null,
                    'warehouse_id' => $line['warehouse_id'] ?? $return->warehouse_id,
                    'description' => trim((string) ($line['description'] ?? 'Returned goods')) ?: 'Returned goods',
                    'qty' => (float) $line['qty'],
                    'unit_cost' => (float) ($line['unit_cost'] ?? 0),
                    'discount' => (float) ($line['discount'] ?? 0),
                    'tax_rate' => (float) ($line['tax_rate'] ?? 0),
                    'batch_no' => $line['batch_no'] ?? null,
                    'sort_order' => $sort++,
                ]);
            }

            $this->recalculate($return);

            $this->audit->record([
                'action' => 'purchase.return_created',
                'entity_type' => 'purchase_return',
                'entity_id' => $return->id,
                'branch_id' => $return->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $return->code,
                    'supplier' => $supplier->name,
                    'reason' => $reason,
                    'total' => (float) $return->total,
                    'receipt' => $receipt?->code,
                    'bill' => $bill?->code,
                    'goods_dispatched' => $return->goods_dispatched,
                ],
            ]);

            return $return->refresh()->load('lines');
        });
    }

    /**
     * Remainder of a receipt line, i.e. what has arrived but has not already
     * gone back. Returns are the second half of a dialogue with the supplier,
     * so the ceiling moves as they happen.
     *
     * @return array<int, array{line: \App\Domain\Purchase\Models\GoodsReceiptLine, returned: float, returnable: float}>
     */
    public function returnableLines(GoodsReceipt $receipt): array
    {
        $receipt->loadMissing('lines');

        $returned = PurchaseReturnLine::query()
            ->whereIn('goods_receipt_line_id', $receipt->lines->pluck('id'))
            ->whereHas('purchaseReturn', fn ($q) => $q->where('status', 'approved'))
            ->get()
            ->groupBy('goods_receipt_line_id')
            ->map(fn ($rows) => round((float) $rows->sum(fn ($line) => (float) $line->qty), 4));

        return $receipt->lines->map(function ($line) use ($returned) {
            $already = (float) ($returned[$line->id] ?? 0);

            return [
                'line' => $line,
                'returned' => $already,
                'returnable' => round(max(0, (float) $line->qty_received - $already), 4),
            ];
        })->values()->all();
    }

    protected function assertReturnable(PurchaseReturn $return, array $line, ?GoodsReceipt $receipt): void
    {
        if ($receipt === null || empty($line['goods_receipt_line_id'])) {
            return;
        }

        $receiptLine = $receipt->lines->firstWhere('id', (int) $line['goods_receipt_line_id']);

        if ($receiptLine === null) {
            throw new RuntimeException('A return line points at a receipt line that belongs to another receipt.');
        }

        $returnable = collect($this->returnableLines($receipt))
            ->firstWhere(fn ($row) => $row['line']->id === $receiptLine->id)['returnable'] ?? 0.0;

        if ((float) $line['qty'] > $returnable + 0.0001) {
            throw new RuntimeException(sprintf(
                'Cannot return %s of %s on line "%s" — only %s of the received quantity is still returnable.',
                number_format((float) $line['qty'], 4),
                $receipt->code,
                $receiptLine->label(),
                number_format((float) $returnable, 4),
            ));
        }
    }

    public function recalculate(PurchaseReturn $return): PurchaseReturn
    {
        $return->loadMissing('lines');

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($return->lines as $line) {
            $net = round((float) $line->qty * (float) $line->unit_cost, 4);
            $discount = min(round((float) $line->discount, 4), $net);
            $taxable = $net - $discount;
            $tax = round($taxable * ((float) $line->tax_rate / 100), 4);

            $line->forceFill(['discount' => $discount, 'line_total' => round($taxable + $tax, 4)])->save();

            $subtotal += $net;
            $discountTotal += $discount;
            $taxTotal += $tax;
        }

        $return->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => round($subtotal - $discountTotal + $taxTotal, 4),
        ])->save();

        return $return->refresh();
    }

    public function submit(PurchaseReturn $return, ?int $actorId = null): PurchaseReturn
    {
        if (! $return->isDraft()) {
            throw new RuntimeException("Only a draft return can be submitted (this one is {$return->status}).");
        }

        $return->forceFill(['status' => 'pending_approval'])->save();

        $this->audit->record([
            'action' => 'purchase.return_submitted',
            'entity_type' => 'purchase_return',
            'entity_id' => $return->id,
            'branch_id' => $return->branch_id,
            'actor_id' => $actorId,
            'after' => ['code' => $return->code, 'total' => (float) $return->total],
        ]);

        return $return->refresh();
    }

    public function approve(PurchaseReturn $return, ?int $actorId = null): PurchaseReturn
    {
        if (! in_array($return->status, ['draft', 'pending_approval'], true)) {
            throw new RuntimeException("This return is already {$return->status} and cannot be approved again.");
        }

        if ($return->created_by !== null && $actorId !== null && (int) $return->created_by === (int) $actorId) {
            throw new RuntimeException('The person who raised a return may not approve it — someone else must check it.');
        }

        if ((float) $return->total <= 0) {
            throw new RuntimeException('A return with no value cannot be approved.');
        }

        return DB::transaction(function () use ($return, $actorId) {
            $return->loadMissing('lines');

            if ($return->purchase_bill_id !== null) {
                $this->takeBillCredit($return);
            }

            $movements = $this->moveStockOut($return, $actorId);
            $entry = $this->postToLedger($return, $actorId);

            $return->forceFill([
                'status' => 'approved',
                'posting_state' => 'posted',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'posted_at' => now(),
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->audit->record([
                'action' => 'purchase.return_approved',
                'entity_type' => 'purchase_return',
                'entity_id' => $return->id,
                'branch_id' => $return->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $return->code,
                    'total' => (float) $return->total,
                    'stock_movements' => $movements,
                    'journal_entry_id' => $entry->id,
                    'bill' => $return->bill?->code,
                ],
            ]);

            return $return->refresh()->load('lines');
        });
    }

    public function cancel(PurchaseReturn $return, string $reason, ?int $actorId = null): PurchaseReturn
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Cancelling a return requires a reason.');
        }

        if ($return->isPosted()) {
            throw new RuntimeException(
                "{$return->code} is posted to the ledger and stock, so it cannot be cancelled — raise a fresh purchase order "
                .'for the goods instead, or reverse through a return of its own.'
            );
        }

        if ($return->status === 'cancelled') {
            throw new RuntimeException("{$return->code} is already cancelled.");
        }

        $return->forceFill([
            'status' => 'cancelled',
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'purchase.return_cancelled',
            'entity_type' => 'purchase_return',
            'entity_id' => $return->id,
            'branch_id' => $return->branch_id,
            'actor_id' => $actorId,
            'after' => ['code' => $return->code, 'reason' => $reason],
        ]);

        return $return->refresh();
    }

    /** Stock goes out for stock-managed lines; a service line has no shelf. */
    protected function moveStockOut(PurchaseReturn $return, ?int $actorId): int
    {
        $actor = $actorId !== null ? User::query()->find($actorId) : null;
        $movements = 0;

        foreach ($return->lines as $line) {
            if ($line->product_id === null) {
                continue;
            }

            $product = Product::query()->find($line->product_id);

            if ($product === null || ! $product->is_stocked) {
                continue;
            }

            $warehouseId = $line->warehouse_id ?? $return->warehouse_id;

            if ($warehouseId === null) {
                throw new RuntimeException(
                    "A return of stocked goods needs a warehouse to take them out of (line \"{$line->description}\")."
                );
            }

            $this->stock->post([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'movement_type' => StockMovement::TYPE_PURCHASE_RETURN_OUT,
                'qty' => (float) $line->qty,
                'unit_cost' => (float) $line->unit_cost,
                'branch_id' => $return->branch_id,
                'source_type' => 'purchase_return',
                'source_id' => $return->id,
                'source_event' => 'approved',
                'idempotency_key' => "pret:{$return->id}:line:{$line->id}",
                'narration' => "Returned to {$return->supplier?->name} on {$return->code}",
            ], $actor);

            $movements++;
        }

        return $movements;
    }

    /**
     * The bill keeps its own arithmetic: credited_amount grows, and due_amount
     * is recomputed by the one definition on the model. An excess is refused —
     * an unapplied debit note is a different document (03-61/03-63).
     */
    protected function takeBillCredit(PurchaseReturn $return): void
    {
        $bill = PurchaseBill::query()->whereKey($return->purchase_bill_id)->lockForUpdate()->firstOrFail();

        if (! $bill->isPosted()) {
            throw new RuntimeException("Bill {$bill->code} is not posted, so a return cannot be credited against it.");
        }

        if ($return->goods_receipt_id !== null && $bill->goods_receipt_id !== null
            && (int) $bill->goods_receipt_id !== (int) $return->goods_receipt_id) {
            throw new RuntimeException(
                "Bill {$bill->code} was raised on a different receipt than the one being returned against."
            );
        }

        $balance = (float) $bill->due_amount;
        $credit = round((float) $return->total, 4);

        if ($credit > $balance + 0.0001) {
            throw new RuntimeException(sprintf(
                'The return is worth %s but bill %s only has %s outstanding. Credit the bill for the balance and raise the '
                .'remainder against the supplier instead — an unapplied debit note is not tracked yet.',
                number_format($credit, 2),
                $bill->code,
                number_format($balance, 2),
            ));
        }

        $bill->forceFill(['credited_amount' => round((float) $bill->credited_amount + $credit, 4)]);
        $due = $bill->balanceAgainst();

        $bill->forceFill([
            'due_amount' => $due,
            'status' => $due <= 0.0001 ? 'paid' : 'partially_paid',
        ])->save();
    }

    protected function postToLedger(PurchaseReturn $return, ?int $actorId): \App\Domain\Accounting\JournalEntry
    {
        $event = $return->goods_receipt_id !== null ? 'purchase_return_posted' : 'purchase_return_expense_posted';
        $net = round((float) $return->subtotal - (float) $return->discount_total, 4);
        $tax = round((float) $return->tax_total, 4);

        $journalLines = [];

        foreach ($this->rules->resolve($event) as $resolved) {
            $amount = match ($resolved['role']) {
                'inventory', 'expense' => $net,
                'tax_payable', 'input_vat' => $tax,
                'ap' => (float) $return->total,
                default => throw new RuntimeException(
                    "Posting rule for [{$event}] uses role [{$resolved['role']}], which a purchase return cannot amount."
                ),
            };

            if (round((float) $amount, 4) === 0.0) {
                continue;
            }

            $journalLines[] = [
                'account_id' => $resolved['account']->id,
                'dc' => $resolved['side'] === 'credit' ? 'credit' : 'debit',
                'amount' => $amount,
                'party_type' => $resolved['role'] === 'ap' ? 'supplier' : null,
                'party_id' => $resolved['role'] === 'ap' ? $return->supplier_id : null,
                'narration' => $return->code.' · '.$return->reasonLabel(),
            ];
        }

        if ($journalLines === []) {
            throw new RuntimeException('The posting rules resolved to no journal lines for '.$return->code.'.');
        }

        $actor = $actorId !== null ? User::query()->find($actorId) : null;

        return $this->journal->post([
            'entry_date' => $return->return_date->toDateString(),
            'description' => 'Purchase return '.$return->code.' — '.($return->supplier?->name ?? 'supplier'),
            'narration' => $return->reason,
            'journal_type' => 'purchase_return',
            'source_type' => 'purchase_return',
            'source_id' => $return->id,
            'source_event' => 'approved',
            'branch_id' => $return->branch_id,
            'lines' => $journalLines,
        ], $actor);
    }

    public function nextCode(int $companyId): string
    {
        $last = PurchaseReturn::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('code');

        $next = $last !== null && preg_match('/PRTN-(\d+)$/', (string) $last, $m) === 1 ? ((int) $m[1]) + 1 : 1;

        return sprintf('PRTN-%05d', $next);
    }

    /** Posted receipts with something left to return (feeds the form picker). */
    public function returnableReceipts(int $limit = 100)
    {
        return GoodsReceipt::query()
            ->where('status', 'posted')
            ->with(['supplier:id,name', 'lines' => fn ($q) => $q->with('product:id,name')->orderBy('sort_order')])
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (GoodsReceipt $receipt) => [
                'receipt' => $receipt,
                'returnable' => round(array_sum(array_map(
                    fn ($row) => $row['returnable'],
                    $this->returnableLines($receipt),
                )), 4),
            ])
            ->filter(fn ($row) => $row['returnable'] > 0)
            ->values();
    }

    /** Posted bills with a balance, so a return can credit the bill it corrects. */
    public function creditableBills(int $limit = 100)
    {
        return PurchaseBill::query()
            ->open()
            ->with('supplier:id,name')
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'code', 'supplier_id', 'bill_date', 'due_date', 'total', 'due_amount', 'status', 'branch_id']);
    }

    /** Receipt lines with their remaining returnable quantity, for the form. */
    public function linesFor(GoodsReceipt $receipt): array
    {
        return collect($this->returnableLines($receipt))
            ->map(fn ($row) => [
                'goods_receipt_line_id' => $row['line']->id,
                'product_id' => $row['line']->product_id,
                'description' => $row['line']->label(),
                'qty_returned' => $row['returned'],
                'qty_received' => (float) $row['line']->qty_received,
                'qty_returnable' => $row['returnable'],
                'unit_cost' => (float) $row['line']->unit_cost,
                'batch_no' => $row['line']->batch_no,
            ])
            ->values()
            ->all();
    }
}
