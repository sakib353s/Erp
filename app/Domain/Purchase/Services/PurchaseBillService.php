<?php

namespace App\Domain\Purchase\Services;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchase bill lifecycle (§03.6).
 *
 * Rules owned here:
 *   · money is recomputed from the lines (qty × unit_cost − discount + tax);
 *     header figures supplied by a client are ignored, never trusted;
 *   · a bill is what creates the payable: approving it posts a real journal
 *     entry through JournalPostingService (accounts come from posting_rules,
 *     never from a hardcoded account id) and stamps posting_state = posted;
 *   · the maker never approves their own bill, and the third pair of eyes is
 *     the whole point — approval is the moment money is owed;
 *   · the three-way match (PO ↔ GRN ↔ bill) is computed and stored. With no
 *     configurable tolerance yet, a mismatch is recorded and surfaced rather
 *     than silently accepted — it does not block, because blocking a real
 *     liability would only move the truth off the system;
 *   · a posted bill is immutable; correction is a credit note (next change).
 */
class PurchaseBillService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected JournalPostingService $journal,
        protected PostingRuleResolver $rules,
    ) {}

    /**
     * @param  array{supplier_id:int,branch_id?:int,purchase_order_id?:?int,goods_receipt_id?:?int,
     *               code?:string,supplier_bill_no?:?string,bill_date:string,due_date?:?string,notes?:?string,
     *               lines:array<int, array{product_id?:?int,description?:string,qty:float,unit_cost:float,
     *                                     discount?:float,tax_rate?:float,purchase_order_line_id?:?int,
     *                                     goods_receipt_line_id?:?int}>}  $data
     */
    public function create(array $data, ?int $actorId = null): PurchaseBill
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for purchase bills.');

        $supplier = Supplier::query()->whereKey($data['supplier_id'] ?? 0)->firstOrFail();
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($line) => (float) ($line['qty'] ?? 0) > 0));

        if ($lines === []) {
            throw new RuntimeException('A purchase bill needs at least one line with a quantity.');
        }

        $billDate = Carbon::parse($data['bill_date']);

        return DB::transaction(function () use ($data, $lines, $supplier, $companyId, $billDate, $actorId) {
            $bill = PurchaseBill::create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? $this->context->branchId() ?? $supplier->company_id,
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'code' => $data['code'] ?? $this->nextCode($companyId),
                'supplier_bill_no' => $data['supplier_bill_no'] ?? null,
                'bill_date' => $billDate->toDateString(),
                'due_date' => $data['due_date'] ?? $this->defaultDueDate($supplier, $billDate)?->toDateString(),
                'status' => 'draft',
                'posting_state' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            foreach ($lines as $index => $line) {
                $bill->lines()->create([
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
                    'product_id' => $line['product_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'qty' => $line['qty'],
                    'unit_cost' => $line['unit_cost'] ?? 0,
                    'discount' => $line['discount'] ?? 0,
                    'tax_rate' => $line['tax_rate'] ?? 0,
                    'sort_order' => $index + 1,
                ]);
            }

            $this->recalculate($bill);

            $this->audit->record([
                'action' => 'purchase.bill_created',
                'entity_type' => 'purchase_bill',
                'entity_id' => $bill->id,
                'branch_id' => $bill->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'code' => $bill->code,
                    'supplier' => $supplier->name,
                    'supplier_bill_no' => $bill->supplier_bill_no,
                    'total' => (float) $bill->total,
                    'lines' => count($lines),
                    'goods_receipt' => $bill->goods_receipt_id,
                ],
            ]);

            return $bill->refresh()->load('lines');
        });
    }

    /**
     * Raise a bill straight from a posted goods receipt — the normal path when
     * the supplier invoices exactly what was delivered.
     */
    public function createFromReceipt(GoodsReceipt $receipt, array $overrides = [], ?int $actorId = null): PurchaseBill
    {
        if (! $receipt->isPosted()) {
            throw new RuntimeException('Only a posted goods receipt can be billed — an unposted receipt is not yet a delivery.');
        }

        $receipt->loadMissing('lines');

        $lines = $receipt->lines->map(fn ($line) => [
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'goods_receipt_line_id' => $line->id,
            'product_id' => $line->product_id,
            'description' => $line->product?->name ?? $line->remarks ?? 'Received item',
            'qty' => (float) $line->qty_received,
            'unit_cost' => (float) $line->unit_cost,
        ])->all();

        return $this->create([
            'supplier_id' => $receipt->supplier_id,
            'branch_id' => $receipt->branch_id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'goods_receipt_id' => $receipt->id,
            'supplier_bill_no' => $overrides['supplier_bill_no'] ?? null,
            'bill_date' => $overrides['bill_date'] ?? now()->toDateString(),
            'due_date' => $overrides['due_date'] ?? null,
            'notes' => $overrides['notes'] ?? null,
            'lines' => $lines,
        ], $actorId);
    }

    /** Recompute line money + header totals, then re-derive the payable. */
    public function recalculate(PurchaseBill $bill): PurchaseBill
    {
        $bill->loadMissing('lines');

        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;

        foreach ($bill->lines as $line) {
            $net = round((float) $line->qty * (float) $line->unit_cost, 4);
            $discount = min(round((float) $line->discount, 4), $net);
            $taxable = $net - $discount;
            $tax = round($taxable * ((float) $line->tax_rate / 100), 4);
            $total = round($taxable + $tax, 4);

            $line->forceFill(['discount' => $discount, 'line_total' => $total])->save();

            $subtotal += $net;
            $discountTotal += $discount;
            $taxTotal += $tax;
        }

        $total = round($subtotal - $discountTotal + $taxTotal, 4);

        $bill->forceFill([
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $taxTotal,
            'total' => $total,
            'due_amount' => $bill->balanceAgainst($total),
        ])->save();

        return $bill->refresh();
    }

    public function submit(PurchaseBill $bill, ?int $actorId = null): PurchaseBill
    {
        if (! $bill->isDraft()) {
            throw new RuntimeException("Only a draft bill can be submitted (this one is {$bill->status}).");
        }

        $bill->forceFill(['status' => 'pending_approval'])->save();

        $this->audit->record([
            'action' => 'purchase.bill_submitted',
            'entity_type' => 'purchase_bill',
            'entity_id' => $bill->id,
            'branch_id' => $bill->branch_id,
            'actor_id' => $actorId,
            'after' => ['status' => 'pending_approval'],
        ]);

        return $bill->refresh();
    }

    /**
     * Approve → the payable becomes real: three-way match is recorded and the
     * journal entry is posted. The maker can never approve their own bill.
     */
    public function approve(PurchaseBill $bill, ?int $actorId = null): PurchaseBill
    {
        if (! in_array($bill->status, ['draft', 'pending_approval'], true)) {
            throw new RuntimeException("This bill cannot be approved from status [{$bill->status}].");
        }

        if ($bill->created_by !== null && $actorId !== null && (int) $bill->created_by === (int) $actorId) {
            throw new RuntimeException('You raised this bill, so you cannot approve it — approval needs a second person.');
        }

        if ((float) $bill->total <= 0) {
            throw new RuntimeException('A bill with no value cannot be approved.');
        }

        $match = $this->runThreeWayMatch($bill);

        return DB::transaction(function () use ($bill, $match, $actorId) {
            $entry = $this->postToLedger($bill, $actorId);

            $bill->forceFill([
                'status' => 'approved',
                'posting_state' => 'posted',
                'approved_by' => $actorId,
                'approved_at' => now(),
                'posted_at' => now(),
                'match_state' => $match['state'],
                'match_summary' => $match['summary'],
                'journal_entry_id' => $entry->id,
            ])->save();

            $this->audit->record([
                'action' => 'purchase.bill_approved',
                'entity_type' => 'purchase_bill',
                'entity_id' => $bill->id,
                'branch_id' => $bill->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'status' => 'approved',
                    'total' => (float) $bill->total,
                    'journal_entry_id' => $entry->id,
                    'journal_entry_no' => $entry->entry_no,
                    'match_state' => $match['state'],
                    'match_summary' => $match['summary'],
                ],
            ]);

            return $bill->refresh()->load('lines');
        });
    }

    /** Only an unposted bill can be cancelled — a liability must not vanish. */
    public function cancel(PurchaseBill $bill, string $reason, ?int $actorId = null): PurchaseBill
    {
        if ($bill->isPosted()) {
            throw new RuntimeException('This bill is posted, so it cannot be cancelled — raise a credit note instead.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Cancelling a bill requires a reason.');
        }

        $bill->forceFill(['status' => 'cancelled', 'cancel_reason' => $reason])->save();

        $this->audit->record([
            'action' => 'purchase.bill_cancelled',
            'entity_type' => 'purchase_bill',
            'entity_id' => $bill->id,
            'branch_id' => $bill->branch_id,
            'actor_id' => $actorId,
            'after' => ['status' => 'cancelled', 'reason' => $reason],
        ]);

        return $bill->refresh();
    }

    /**
     * Three-way match: what we ordered (PO) vs what arrived (GRN) vs what the
     * supplier billed. Returns the state plus a human summary; the caller
     * stores both on the bill so the check survives the request that made it.
     *
     * @return array{state:string, summary:string}
     */
    public function runThreeWayMatch(PurchaseBill $bill): array
    {
        $bill->loadMissing('lines.receiptLine', 'receipt');

        if ($bill->goods_receipt_id === null && $bill->purchase_order_id === null) {
            return [
                'state' => 'not_applicable',
                'summary' => 'Direct bill — no purchase order or goods receipt to match against.',
            ];
        }

        $qtyIssues = [];
        $priceIssues = [];
        $missingReceipt = [];

        foreach ($bill->lines as $line) {
            $ordered = $line->orderLine;
            $receivedLine = $line->receiptLine;

            if ($bill->goods_receipt_id !== null && $receivedLine === null) {
                $missingReceipt[] = $line->description ?? ('line #'.$line->id);
            }

            if ($receivedLine !== null && round((float) $line->qty, 4) > round((float) $receivedLine->qty_received, 4)) {
                $qtyIssues[] = sprintf(
                    '%s: billed %s vs received %s',
                    $line->description ?? $line->product?->name ?? 'line',
                    rtrim(rtrim(number_format((float) $line->qty, 4, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $receivedLine->qty_received, 4, '.', ''), '0'), '.'),
                );
            }

            if ($ordered !== null && round((float) $line->unit_cost, 4) !== round((float) $ordered->unit_price, 4)) {
                $priceIssues[] = sprintf(
                    '%s: billed at %s vs ordered at %s',
                    $line->description ?? $line->product?->name ?? 'line',
                    number_format((float) $line->unit_cost, 2),
                    number_format((float) $ordered->unit_price, 2),
                );
            }
        }

        if ($missingReceipt !== []) {
            return [
                'state' => 'unmatched',
                'summary' => 'Lines on this bill are not on the linked goods receipt: '.implode(' · ', array_slice($missingReceipt, 0, 3)),
            ];
        }

        if ($qtyIssues !== []) {
            return ['state' => 'qty_mismatch', 'summary' => implode(' · ', array_slice($qtyIssues, 0, 3))];
        }

        if ($priceIssues !== []) {
            return ['state' => 'price_mismatch', 'summary' => implode(' · ', array_slice($priceIssues, 0, 3))];
        }

        return ['state' => 'matched', 'summary' => 'Quantities and prices agree with the order and the receipt.'];
    }

    /** Post the payable: accounts come from posting_rules, never from code. */
    protected function postToLedger(PurchaseBill $bill, ?int $actorId): \App\Domain\Accounting\JournalEntry
    {
        $event = $bill->goods_receipt_id !== null ? 'purchase_bill_posted' : 'purchase_bill_expense_posted';
        $net = round((float) $bill->subtotal - (float) $bill->discount_total, 4);
        $tax = round((float) $bill->tax_total, 4);

        $journalLines = [];

        foreach ($this->rules->resolve($event) as $resolved) {
            $amount = match ($resolved['role']) {
                'inventory', 'expense' => $net,
                'tax_payable', 'input_vat' => $tax,
                'ap' => (float) $bill->total,
                default => throw new RuntimeException(
                    "Posting rule for [{$event}] uses role [{$resolved['role']}], which this document type cannot amount."
                ),
            };

            // A zero line is dropped rather than posted: a 0.00 debit is noise
            // in the ledger, and dropping it keeps the entry balanced.
            if (round((float) $amount, 4) === 0.0) {
                continue;
            }

            $journalLines[] = [
                'account_id' => $resolved['account']->id,
                'dc' => $resolved['side'] === 'credit' ? 'credit' : 'debit',
                'amount' => $amount,
                'party_type' => $resolved['role'] === 'ap' ? 'supplier' : null,
                'party_id' => $resolved['role'] === 'ap' ? $bill->supplier_id : null,
                'narration' => $bill->code.($bill->supplier_bill_no ? ' · supplier bill '.$bill->supplier_bill_no : ''),
            ];
        }

        if ($journalLines === []) {
            throw new RuntimeException('The posting rules resolved to no journal lines for '.$bill->code.'.');
        }

        $actor = $actorId !== null ? User::query()->find($actorId) : null;

        return $this->journal->post([
            'entry_date' => $bill->bill_date->toDateString(),
            'description' => 'Purchase bill '.$bill->code.' — '.($bill->supplier?->name ?? 'supplier'),
            'narration' => $bill->notes,
            'journal_type' => 'purchase',
            'source_type' => 'purchase_bill',
            'source_id' => $bill->id,
            'source_event' => 'posted',
            'branch_id' => $bill->branch_id,
            'lines' => $journalLines,
        ], $actor);
    }

    protected function defaultDueDate(Supplier $supplier, Carbon $billDate): ?Carbon
    {
        $days = (int) ($supplier->payment_terms_days ?? 0);

        return $days > 0 ? $billDate->copy()->addDays($days) : null;
    }

    public function nextCode(int $companyId): string
    {
        $last = PurchaseBill::query()
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->value('code');

        $next = $last !== null && preg_match('/BILL-(\d+)$/', (string) $last, $m) === 1 ? ((int) $m[1]) + 1 : 1;

        return sprintf('BILL-%05d', $next);
    }

    /** Receipts that are posted but not yet billed (feeds the form picker). */
    public function billableReceipts(int $limit = 100)
    {
        return GoodsReceipt::query()
            ->where('status', 'posted')
            ->whereDoesntHave('bills')
            ->with('supplier:id,name')
            ->orderByDesc('received_date')
            ->limit($limit)
            ->get(['id', 'code', 'supplier_id', 'received_date', 'total', 'purchase_order_id']);
    }

    /** Open orders, for a direct bill that still wants to cite its order. */
    public function openOrders(int $limit = 100)
    {
        return PurchaseOrder::query()
            ->open()
            ->with('supplier:id,name')
            ->orderByDesc('order_date')
            ->limit($limit)
            ->get(['id', 'code', 'supplier_id', 'order_date', 'total']);
    }

    /** Lines of one receipt, for prefilling the bill form. */
    public function linesFor(?GoodsReceipt $receipt): array
    {
        if ($receipt === null) {
            return [];
        }

        return $receipt->loadMissing('lines.product')->lines->map(fn ($line) => [
            'goods_receipt_line_id' => $line->id,
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'product_id' => $line->product_id,
            'description' => $line->product?->name ?? 'Received item',
            'qty' => (float) $line->qty_received,
            'unit_cost' => (float) $line->unit_cost,
        ])->all();
    }
}
