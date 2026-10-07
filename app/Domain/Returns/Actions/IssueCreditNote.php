<?php

namespace App\Domain\Returns\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Returns\SalesReturn;
use App\Domain\Sales\CreditNote;
use App\Domain\Sales\CreditNoteLine;
use App\Domain\Sales\Invoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * IssueCreditNote (07-03/02-54). Inverse GL via sales_credit_note_issued
 * posting rule (Dr sales, Cr ar — mirror of invoice). Optional sales_return
 * inverse-COGS rule when restock. Original invoice journal stays immutable;
 * credit note is a NEW balanced entry linked to the invoice.
 */
class IssueCreditNote
{
    public function __construct(
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected NumberingService $numbering,
        protected TenantContext $context,
        protected AuditRecorder $audit,
    ) {}

    public function handle(SalesReturn $salesReturn, array $payload, Request $request): CreditNote
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        return DB::transaction(function () use ($salesReturn, $payload, $companyId, $request) {
            $fresh = SalesReturn::query()
                ->with('lines')
                ->whereKey($salesReturn->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($fresh->status, ['received', 'inspected', 'requested', 'approved'], true)) {
                throw new RuntimeException("Return {$fresh->return_no} cannot issue a credit note from status [{$fresh->status}].");
            }

            if ($fresh->credit_note_id !== null) {
                throw new RuntimeException("Return {$fresh->return_no} already has a credit note.");
            }

            $invoice = Invoice::query()->findOrFail($fresh->invoice_id);

            $docType = DocumentType::query()->where('code', 'credit_note')->first()
                ?? abort(500, 'credit_note document type is not seeded.');

            $cnNo = $this->numbering->allocate(
                $docType->id,
                $request->user()->default_branch_id,
            );

            $creditNote = CreditNote::create([
                'company_id' => $companyId,
                'branch_id' => $fresh->branch_id ?? $invoice->branch_id,
                'customer_id' => $fresh->customer_id,
                'invoice_id' => $invoice->id,
                'sales_return_id' => $fresh->id,
                'document_type_id' => $docType->id,
                'credit_note_no' => $cnNo,
                'status' => 'draft',
                'posting_state' => 'draft',
                'note_date' => now()->toDateString(),
                'subtotal' => $fresh->subtotal,
                'tax' => $fresh->tax,
                'grand_total' => $fresh->grand_total,
                'printed_title' => $docType->printed_title,
                'reason' => $payload['reason'] ?? $fresh->notes,
                'notes' => $payload['notes'] ?? $fresh->notes,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($fresh->lines as $rl) {
                $lineNo++;
                CreditNoteLine::create([
                    'company_id' => $companyId,
                    'credit_note_id' => $creditNote->id,
                    'line_no' => $lineNo,
                    'product_id' => $rl->product_id,
                    'invoice_line_id' => $rl->invoice_line_id,
                    'description' => $rl->description,
                    'qty' => $rl->qty,
                    'unit_price' => $rl->unit_price,
                    'tax' => $rl->tax,
                    'line_total' => $rl->line_total,
                ]);
            }

            // Post inverse GL: Dr sales / Cr ar (and tax reverse when present)
            $grand = (float) $creditNote->grand_total;
            $tax = (float) $creditNote->tax;
            $salesDebit = round($grand - $tax, 4);

            $resolved = $this->rules->resolve('sales_credit_note_issued', 'credit_note');
            $byRole = [];
            foreach ($resolved as $r) {
                $byRole[$r['role']] = $r;
            }

            $lines = [];
            if (isset($byRole['sales']) && $salesDebit > 0) {
                $lines[] = [
                    'account_id' => $byRole['sales']['account']->id,
                    'dc' => 'debit',
                    'amount' => $salesDebit,
                ];
            }
            if (isset($byRole['tax_payable']) && $tax > 0) {
                $lines[] = [
                    'account_id' => $byRole['tax_payable']['account']->id,
                    'dc' => 'debit',
                    'amount' => $tax,
                ];
            }
            if (isset($byRole['ar']) && $grand > 0) {
                $lines[] = [
                    'account_id' => $byRole['ar']['account']->id,
                    'dc' => 'credit',
                    'amount' => $grand,
                    'party_type' => $invoice->customer_id ? 'customer' : null,
                    'party_id' => $invoice->customer_id,
                ];
            }

            if (count($lines) >= 2) {
                $entry = $this->posting->post([
                    'entry_date' => $creditNote->note_date->toDateString(),
                    'description' => "Credit note {$cnNo} against {$invoice->invoice_no}",
                    'journal_type' => 'sales',
                    'source_type' => 'credit_note',
                    'source_id' => $creditNote->id,
                    'source_event' => 'sales_credit_note_issued',
                    'branch_id' => $creditNote->branch_id,
                    'lines' => $lines,
                ], $request->user());
                $creditNote->journal_entry_id = $entry->id;
            }

            // Inverse COGS when restock: Dr inventory / Cr cogs via sales_return rule
            try {
                $cogsResolved = $this->rules->resolve('sales_return');
                $cogsByRole = [];
                foreach ($cogsResolved as $r) {
                    $cogsByRole[$r['role']] = $r;
                }
                if (isset($cogsByRole['inventory'], $cogsByRole['cogs'])) {
                    // Amount left to caller as 0 if unknown cost — skip zero lines
                    $returnCost = round((float) ($payload['cogs_amount'] ?? 0), 4);
                    if ($returnCost > 0) {
                        $this->posting->post([
                            'entry_date' => $creditNote->note_date->toDateString(),
                            'description' => "Return COGS reverse {$cnNo}",
                            'journal_type' => 'sales',
                            'source_type' => 'credit_note',
                            'source_id' => $creditNote->id,
                            'source_event' => 'sales_return',
                            'branch_id' => $creditNote->branch_id,
                            'lines' => [
                                ['account_id' => $cogsByRole['inventory']['account']->id, 'dc' => 'debit', 'amount' => $returnCost],
                                ['account_id' => $cogsByRole['cogs']['account']->id, 'dc' => 'credit', 'amount' => $returnCost],
                            ],
                        ], $request->user());
                    }
                }
            } catch (RuntimeException) {
                // sales_return rule optional
            }

            $creditNote->status = 'issued';
            $creditNote->posting_state = 'posted';
            $creditNote->save();

            $fresh->status = 'credited';
            $fresh->credit_note_id = $creditNote->id;
            $fresh->save();

            // Invoice history is not rewritten — credit note is the separate DOC;
            // paid/due stay as issued for audit trail integrity.

            $this->audit->record([
                'action' => 'returns.credit_note_issued',
                'entity_type' => 'credit_note',
                'entity_id' => $creditNote->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'credit_note_no' => $cnNo,
                    'invoice_no' => $invoice->invoice_no,
                    'grand_total' => $grand,
                    'journal_entry_id' => $creditNote->journal_entry_id,
                ],
            ]);

            return $creditNote->load('lines');
        });
    }
}
