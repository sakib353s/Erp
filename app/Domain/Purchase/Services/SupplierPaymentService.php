<?php

namespace App\Domain\Purchase\Services;

use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Sales\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Supplier payments (§03.7, feeding 03-47 / 03-52).
 *
 * Rules owned here:
 *   · money leaves through the same `payments` table the customer side uses —
 *     one money table, one allocation table, no parallel truth;
 *   · the journal entry is Dr Accounts Payable, Cr Cash/Bank, resolved from
 *     posting_rules (`supplier_payment` for cash, `supplier_payment_bank` for
 *     bank) so no account id is hardcoded and cash vs bank is a real choice;
 *   · a payment may not exceed the bill's balance, cannot be made against a
 *     cancelled or unapproved bill, and reduces the bill through `paid_amount`
 *     / `due_amount` — the payable the supplier profile ages;
 *   · idempotency: the same key returns the original payment instead of paying
 *     a supplier twice (retries are normal, double payment is not).
 */
class SupplierPaymentService
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected JournalPostingService $journal,
        protected PostingRuleResolver $rules,
        protected NumberingService $numbering,
    ) {}

    /**
     * @param  array{bill_id:int,amount:float,method?:string,paid_at?:string,reference?:?string,
     *               narration?:?string,idempotency_key?:?string}  $payload
     */
    public function handle(array $payload, ?int $actorId = null): Payment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context for supplier payments.');
        $amount = round((float) ($payload['amount'] ?? 0), 4);

        if ($amount <= 0) {
            throw new RuntimeException('A supplier payment must be greater than zero.');
        }

        return DB::transaction(function () use ($payload, $amount, $companyId, $actorId) {
            $key = $payload['idempotency_key'] ?? null;

            if ($key !== null) {
                $existing = Payment::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $key)
                    ->first();

                if ($existing !== null) {
                    return $existing->load('allocations');
                }
            }

            $bill = PurchaseBill::query()->findOrFail($payload['bill_id']);

            if (! $bill->isPosted()) {
                throw new RuntimeException("Bill {$bill->code} is not posted, so it is not payable yet.");
            }

            if ($bill->status === 'cancelled') {
                throw new RuntimeException("Bill {$bill->code} is cancelled — nothing can be paid against it.");
            }

            $balance = round((float) $bill->due_amount, 4);

            if ($balance <= 0) {
                throw new RuntimeException("Bill {$bill->code} is already settled in full.");
            }

            if ($amount > $balance + 0.0001) {
                throw new RuntimeException(sprintf(
                    'Payment %s exceeds the balance of %s on %s.',
                    number_format($amount, 2),
                    number_format($balance, 2),
                    $bill->code,
                ));
            }

            $method = in_array($payload['method'] ?? 'cash', ['cash', 'bank', 'cheque', 'mobile'], true)
                ? ($payload['method'] ?? 'cash')
                : 'cash';

            $event = $method === 'cash' ? 'supplier_payment' : 'supplier_payment_bank';

            $resolved = [];
            foreach ($this->rules->resolve($event, null, now()) as $rule) {
                $resolved[$rule['role']] = $rule;
            }

            $ap = $resolved['ap']['account'] ?? null;
            $money = $resolved[$method === 'cash' ? 'cash' : 'counter']['account'] ?? null;

            if ($ap === null || $money === null) {
                throw new RuntimeException("Posting rules for [{$event}] are not configured (ap + money roles).");
            }

            $docType = DocumentType::query()->where('code', 'expense_voucher')->first()
                ?? abort(500, 'expense_voucher document type is not seeded.');

            $voucherNo = $this->numbering->allocate($docType->id, $bill->branch_id);

            $paidAt = $payload['paid_at'] ?? now()->toDateString();
            $actor = $actorId !== null ? User::query()->find($actorId) : null;

            $entry = $this->journal->post([
                'entry_date' => $paidAt,
                'description' => "Payment {$voucherNo} to {$bill->supplier?->name} against {$bill->code}",
                'narration' => $payload['narration'] ?? null,
                'journal_type' => 'purchase_payment',
                'source_type' => 'supplier_payment',
                'source_id' => $bill->id,
                'source_event' => 'supplier_payment',
                'branch_id' => $bill->branch_id,
                'lines' => [
                    [
                        'account_id' => $ap->id,
                        'dc' => 'debit',
                        'amount' => $amount,
                        'party_type' => 'supplier',
                        'party_id' => $bill->supplier_id,
                    ],
                    [
                        'account_id' => $money->id,
                        'dc' => 'credit',
                        'amount' => $amount,
                    ],
                ],
            ], $actor);

            $payment = Payment::create([
                'company_id' => $companyId,
                'branch_id' => $bill->branch_id,
                'supplier_id' => $bill->supplier_id,
                'account_id' => $money->id,
                'receipt_no' => $voucherNo,
                'direction' => 'out',
                'method' => $method,
                'amount' => number_format($amount, 4, '.', ''),
                'status' => 'posted',
                'paid_at' => $paidAt,
                'reference' => $payload['reference'] ?? null,
                'narration' => $payload['narration'] ?? null,
                'idempotency_key' => $key,
                'journal_entry_id' => $entry->id,
                'created_by' => $actorId,
            ]);

            PaymentAllocation::create([
                'company_id' => $companyId,
                'payment_id' => $payment->id,
                'allocatable_type' => PurchaseBill::class,
                'allocatable_id' => $bill->id,
                'amount' => number_format($amount, 4, '.', ''),
                'branch_id' => $bill->branch_id,
                'created_by' => $actorId,
            ]);

            $newPaid = round((float) $bill->paid_amount + $amount, 4);

            $bill->forceFill([
                'paid_amount' => $newPaid,
            ]);

            // One definition of the balance for cash and credit alike:
            // due = total − paid − credited (see PurchaseBill::balanceAgainst).
            $newDue = $bill->balanceAgainst();

            $bill->forceFill([
                'due_amount' => $newDue,
                'status' => $newDue <= 0.0001 ? 'paid' : 'partially_paid',
            ])->save();

            $this->audit->record([
                'action' => 'purchase.payment_recorded',
                'entity_type' => 'payment',
                'entity_id' => $payment->id,
                'branch_id' => $bill->branch_id,
                'actor_id' => $actorId,
                'after' => [
                    'voucher_no' => $voucherNo,
                    'bill' => $bill->code,
                    'supplier' => $bill->supplier?->name,
                    'amount' => $amount,
                    'method' => $method,
                    'bill_status' => $bill->status,
                    'journal_entry_id' => $entry->id,
                ],
            ]);

            return $payment->refresh()->load('allocations');
        });
    }
}
