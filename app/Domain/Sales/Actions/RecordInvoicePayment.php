<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * RecordInvoicePayment (money receipt). GL: Dr Cash/Bank, Cr AR via
 * posting_rules (receipt). Allocates to invoice; updates paid/due/status.
 */
class RecordInvoicePayment
{
    public function __construct(
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    /**
     * @param array{
     *   invoice_id: int,
     *   amount: float,
     *   method?: string,
     *   payment_method_id?: int|null,
     *   paid_at?: string,
     *   reference?: string|null,
     *   narration?: string|null,
     *   idempotency_key?: string|null,
     * } $payload
     */
    public function handle(array $payload, Request $request): Payment
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');
        $amount = round((float) ($payload['amount'] ?? 0), 4);

        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($payload, $amount, $companyId, $request) {
            if (! empty($payload['idempotency_key'])) {
                $existing = Payment::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $payload['idempotency_key'])
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->findOrFail($payload['invoice_id']);

            if (! in_array($invoice->status, ['issued', 'partial', 'paid'], true)) {
                throw new RuntimeException("Invoice {$invoice->invoice_no} is not open for payment (status [{$invoice->status}]).");
            }

            $due = (float) $invoice->due_amount;
            if ($amount > $due + 0.0001) {
                throw new RuntimeException(sprintf(
                    'Payment %.4f exceeds due amount %.4f on invoice %s.',
                    $amount,
                    $due,
                    $invoice->invoice_no,
                ));
            }

            $docType = DocumentType::query()->where('code', 'money_receipt')->first()
                ?? abort(500, 'money_receipt document type is not seeded.');

            $receiptNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $method = $payload['method'] ?? 'cash';

            // Resolve cash/bank account via posting rule (never hardcode)
            $resolved = $this->rules->resolve('receipt');
            $byRole = [];
            foreach ($resolved as $r) {
                $byRole[$r['role']] = $r;
            }

            $cashAccount = $byRole['cash']['account'] ?? null;
            $arAccount = $byRole['ar']['account'] ?? null;

            if ($cashAccount === null || $arAccount === null) {
                throw new RuntimeException('receipt posting rule is not configured (cash/ar roles).');
            }

            $entry = $this->posting->post([
                'entry_date' => ($payload['paid_at'] ?? now()->toDateString()),
                'description' => "Receipt {$receiptNo} against {$invoice->invoice_no}",
                'journal_type' => 'receipt',
                'source_type' => 'payment',
                'source_event' => 'receipt',
                'branch_id' => $invoice->branch_id,
                'lines' => [
                    [
                        'account_id' => $cashAccount->id,
                        'dc' => 'debit',
                        'amount' => $amount,
                    ],
                    [
                        'account_id' => $arAccount->id,
                        'dc' => 'credit',
                        'amount' => $amount,
                        'party_type' => $invoice->customer_id ? 'customer' : null,
                        'party_id' => $invoice->customer_id,
                    ],
                ],
            ], $request->user());

            $payment = Payment::create([
                'company_id' => $companyId,
                'branch_id' => $invoice->branch_id,
                'customer_id' => $invoice->customer_id,
                'payment_method_id' => $payload['payment_method_id'] ?? null,
                'account_id' => $cashAccount->id,
                'receipt_no' => $receiptNo,
                'direction' => 'in',
                'method' => $method,
                'amount' => number_format($amount, 4, '.', ''),
                'status' => 'posted',
                'paid_at' => $payload['paid_at'] ?? now()->toDateString(),
                'reference' => $payload['reference'] ?? null,
                'narration' => $payload['narration'] ?? null,
                'idempotency_key' => $payload['idempotency_key'] ?? null,
                'journal_entry_id' => $entry->id,
                'created_by' => $request->user()->id,
            ]);

            PaymentAllocation::create([
                'company_id' => $companyId,
                'payment_id' => $payment->id,
                'allocatable_type' => Invoice::class,
                'allocatable_id' => $invoice->id,
                'amount' => number_format($amount, 4, '.', ''),
                'branch_id' => $invoice->branch_id,
                'created_by' => $request->user()->id,
            ]);

            $newPaid = round((float) $invoice->paid_amount + $amount, 4);
            $newDue = round(max(0, (float) $invoice->grand_total - $newPaid), 4);

            $invoice->paid_amount = number_format($newPaid, 4, '.', '');
            $invoice->due_amount = number_format($newDue, 4, '.', '');
            $invoice->status = $newDue <= 0.0001 ? 'paid' : 'partial';
            $invoice->save();

            $this->audit->record([
                'action' => 'sales.payment_recorded',
                'entity_type' => 'payment',
                'entity_id' => $payment->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'receipt_no' => $receiptNo,
                    'invoice_no' => $invoice->invoice_no,
                    'amount' => $amount,
                    'invoice_status' => $invoice->status,
                ],
            ]);

            return $payment;
        });
    }
}
