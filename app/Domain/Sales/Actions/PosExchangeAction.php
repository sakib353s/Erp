<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Accounting\Services\JournalPostingService;
use App\Domain\Accounting\Services\PostingRuleResolver;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\DocumentType;
use App\Domain\Foundation\Services\NumberingService;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Customer;
use App\Domain\Returns\Exchange;
use App\Domain\Returns\ExchangeLine;
use App\Domain\Returns\SalesReturnLine;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\InvoiceLine;
use App\Domain\Sales\PosSession;
use App\Domain\Sales\PosTransaction;
use App\Domain\Sales\Services\PricingService;
use App\Domain\Sales\Services\TotalsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * PosExchangeAction (02-39). Counter exchange against a POS invoice
 * inside the open session, on one document with two legs:
 *
 *  - return leg: quantities come back off the original invoice at its
 *    invoiced prices (shared remaining-qty guard with the returns
 *    pipeline) and restock through StockLedgerService (SALES_RETURN in);
 *  - issue leg: new items are server-priced onto a fresh POS invoice
 *    that IssueInvoice walks (GL Dr ar / Cr sales + SALES_OUT + COGS);
 *  - ACCT differential: a credit entry for the returned goods
 *    (Dr sales / Cr ar — sales_credit_note_issued rule) then the signed
 *    difference settles for real: D > 0 is collected as a receipt
 *    (payment row), D < 0 refunds through the refund rule, D = 0 needs
 *    no money. Net accounts-receivable effect is zero — the goods credit
 *    and the settlement always add up to the new invoice.
 *
 * The drawer follows the money: a positive cash difference lands in
 * session.cash_sales, a negative one in session.cash_out, so the close
 * math balances; non-cash differences never touch the drawer.
 * A supplied idempotency_key replays the same exchange instead of
 * exchanging twice.
 */
class PosExchangeAction
{
    public function __construct(
        protected TenantContext $context,
        protected AuditRecorder $audit,
        protected NumberingService $numbering,
        protected PricingService $pricing,
        protected TotalsCalculator $totals,
        protected StockLedgerService $ledger,
        protected PostingRuleResolver $rules,
        protected JournalPostingService $posting,
        protected IssueInvoice $issueInvoice,
        protected RecordInvoicePayment $recordPayment,
    ) {}

    /**
     * @param array{
     *   invoice_id: int,
     *   lines: array<int, array{invoice_line_id: int, qty?: float|null}>,
     *   exchange_lines: array<int, array{product_id: int, qty?: float|null, unit_price?: float|null}>,
     *   pos_session_id?: int|null,
     *   payment_method?: string,
     *   notes?: string|null,
     *   idempotency_key?: string|null,
     * } $payload
     * @return array{exchange: Exchange, invoice: Invoice, payment: object|null, session: ?PosSession}
     */
    public function handle(array $payload, Request $request): array
    {
        $companyId = $this->context->companyId() ?? abort(500, 'No company context.');

        $returnLines = array_values(array_filter(
            $payload['lines'] ?? [],
            fn (array $line) => (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($returnLines === []) {
            throw new RuntimeException('POS exchange requires at least one return line with a positive qty.');
        }

        $issueLines = array_values(array_filter(
            $payload['exchange_lines'] ?? [],
            fn (array $line) => (float) ($line['qty'] ?? 0) > 0,
        ));

        if ($issueLines === []) {
            throw new RuntimeException('POS exchange requires at least one item to exchange for.');
        }

        $method = $payload['payment_method'] ?? 'cash';
        if (! in_array($method, ['cash', 'bank', 'mobile'], true)) {
            throw new RuntimeException("Unsupported POS exchange settlement method [{$method}].");
        }

        return DB::transaction(function () use ($payload, $returnLines, $issueLines, $companyId, $method, $request) {
            $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));

            if ($idempotencyKey !== '') {
                $existing = Exchange::query()
                    ->where('company_id', $companyId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    // Replay: the exchange already moved stock and money.
                    return [
                        'exchange' => $existing,
                        'invoice' => $existing->newInvoice,
                        'payment' => null,
                        'session' => PosSession::query()
                            ->where('company_id', $companyId)
                            ->where('status', 'open')
                            ->where('branch_id', $request->user()->default_branch_id)
                            ->first(),
                    ];
                }
            }

            $invoice = Invoice::query()
                ->where('company_id', $companyId)
                ->findOrFail($payload['invoice_id']);

            if ($invoice->invoice_type !== 'pos') {
                throw new RuntimeException(sprintf(
                    'POS exchange requires a POS invoice (%s is [%s]).',
                    $invoice->invoice_no,
                    $invoice->invoice_type,
                ));
            }

            if (! in_array($invoice->status, ['issued', 'partial', 'paid'], true)) {
                throw new RuntimeException("Invoice {$invoice->invoice_no} is not open for exchange (status [{$invoice->status}]).");
            }

            $session = $this->requireOpenSession($payload, $companyId, $request);

            if ($session->warehouse_id === null) {
                throw new RuntimeException('POS session has no warehouse; reopen with a warehouse to exchange stock.');
            }

            $customer = $invoice->customer_id !== null
                ? Customer::query()->where('company_id', $companyId)->find($invoice->customer_id)
                : null;

            // ---- Return leg: validate against the invoice, price at the invoiced rate.
            $returnSubtotal = 0.0;
            $returnTax = 0.0;
            $resolvedReturn = [];

            foreach ($returnLines as $line) {
                $invoiceLine = InvoiceLine::query()
                    ->where('company_id', $companyId)
                    ->where('invoice_id', $invoice->id)
                    ->findOrFail($line['invoice_line_id']);

                $qty = (float) ($line['qty'] ?? 0);

                $alreadyReturned = (float) SalesReturnLine::query()
                    ->whereHas('salesReturn', function ($q) use ($companyId, $invoice) {
                        $q->where('company_id', $companyId)
                            ->where('invoice_id', $invoice->id)
                            ->whereIn('status', ['requested', 'approved', 'received', 'inspected', 'credited', 'refunded']);
                    })
                    ->where('invoice_line_id', $invoiceLine->id)
                    ->sum('qty')
                    // Exchanges settle on their own document: count them too so
                    // returns + exchanges can never jointly exceed the invoice.
                    + (float) ExchangeLine::query()
                        ->where('company_id', $companyId)
                        ->where('invoice_line_id', $invoiceLine->id)
                        ->sum('qty');

                $invoiced = (float) $invoiceLine->qty;
                if ($alreadyReturned + $qty > $invoiced + 1e-9) {
                    throw new RuntimeException(sprintf(
                        'Return qty %.4f exceeds remaining invoiced qty %.4f on line %d.',
                        $qty,
                        $invoiced - $alreadyReturned,
                        $invoiceLine->line_no,
                    ));
                }

                $unit = (float) $invoiceLine->unit_price;
                $lineTaxRatio = (float) $invoiceLine->qty > 0
                    ? ((float) $invoiceLine->tax) / (float) $invoiceLine->qty
                    : 0.0;
                $tax = round($qty * $lineTaxRatio, 4);
                $net = round($qty * $unit, 4);
                $lineTotal = round($net + $tax, 4);
                $returnSubtotal += $net;
                $returnTax += $tax;

                $resolvedReturn[] = [
                    'invoice_line_id' => $invoiceLine->id,
                    'product_id' => $invoiceLine->product_id,
                    'description' => $invoiceLine->description,
                    'qty' => $qty,
                    'unit_price' => $unit,
                    'tax' => $tax,
                    'line_total' => $lineTotal,
                ];
            }

            // ---- Issue leg: server-authoritative pricing on the original customer.
            $priced = [];
            foreach ($issueLines as $i => $line) {
                $product = Product::query()
                    ->where('company_id', $companyId)
                    ->findOrFail($line['product_id']);

                if (! $product->is_active) {
                    throw new RuntimeException("Product {$product->sku} is inactive.");
                }

                $qty = (float) ($line['qty'] ?? 0);

                $priced[$i] = [
                    'product_id' => $product->id,
                    'qty' => $qty,
                    'unit_price' => $this->pricing->resolveUnitPrice(
                        $product,
                        $customer,
                        isset($line['unit_price']) && $line['unit_price'] !== null && $line['unit_price'] !== ''
                            ? (float) $line['unit_price']
                            : null,
                        now()->toDateString(),
                        max(1, (int) $qty),
                    ),
                    'discount' => 0.0,
                    'description' => $product->name,
                ];
            }

            $calc = $this->totals->calculate(
                $priced,
                0.0,
                0.0,
                $invoice->tax_code,
                now()->toDateString(),
                (bool) $invoice->tax_applicable,
            );

            $docType = DocumentType::query()->where('code', 'exchange')->first();
            $exchangeNo = $docType !== null
                ? $this->numbering->allocate($docType->id, $request->user()->default_branch_id)
                : 'EXC-'.now()->format('YmdHis').'-'.uniqid();

            $returnTotal = round(array_sum(array_column($resolvedReturn, 'line_total')), 4);

            $exchange = Exchange::create([
                'company_id' => $companyId,
                'branch_id' => $session->branch_id ?? $invoice->branch_id,
                'warehouse_id' => $session->warehouse_id,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'new_invoice_id' => null,
                'pos_session_id' => $session->id,
                'exchange_no' => $exchangeNo,
                'status' => 'completed',
                'payment_method' => $method,
                'return_total' => number_format($returnTotal, 4, '.', ''),
                'exchange_total' => 0,
                'price_differential' => 0,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $lineNo = 0;
            foreach ($resolvedReturn as $r) {
                $lineNo++;
                ExchangeLine::create(array_merge($r, [
                    'company_id' => $companyId,
                    'exchange_id' => $exchange->id,
                    'direction' => 'return',
                    'line_no' => $lineNo,
                ]));
            }

            // Return leg STK in: goods come back to the warehouse they left.
            $restockWarehouseId = $invoice->warehouse_id ?? $session->warehouse_id;
            foreach ($resolvedReturn as $r) {
                if ($r['product_id'] === null || $r['qty'] <= 0) {
                    continue;
                }

                $this->ledger->post([
                    'product_id' => $r['product_id'],
                    'warehouse_id' => $restockWarehouseId,
                    'movement_type' => StockMovement::TYPE_SALES_RETURN,
                    'qty' => $r['qty'],
                    'source_type' => 'exchange',
                    'source_id' => $exchange->id,
                    'source_event' => 'pos_exchange',
                    'idempotency_key' => sprintf('pos-exchange-in:%d:%d', $exchange->id, $r['invoice_line_id']),
                    'narration' => "Exchange {$exchangeNo} restock against {$invoice->invoice_no}",
                ], $request->user());
            }

            // ---- Issue leg: a fresh POS invoice, exactly like a counter sale.
            $invoiceDocType = DocumentType::query()->where('code', 'invoice')->first()
                ?? abort(500, 'invoice document type is not seeded.');

            $newInvoiceNo = $this->numbering->allocate(
                $invoiceDocType->id,
                $request->user()->default_branch_id,
            );

            $grand = (float) $calc['grand_total'];

            $newInvoice = Invoice::create([
                'company_id' => $companyId,
                'branch_id' => $session->branch_id ?? $invoice->branch_id,
                'warehouse_id' => $session->warehouse_id,
                'customer_id' => $invoice->customer_id,
                'sales_order_id' => null,
                'document_type_id' => $invoiceDocType->id,
                'pos_session_id' => $session->id,
                'invoice_no' => $newInvoiceNo,
                'status' => 'draft',
                'invoice_type' => 'pos',
                'workflow_state' => 'none',
                'posting_state' => 'draft',
                'invoice_date' => now()->toDateString(),
                'due_date' => null,
                'currency' => $invoice->currency ?? 'BDT',
                'subtotal' => $calc['subtotal'],
                'doc_discount' => $calc['doc_discount'],
                'coupon_code' => null,
                'coupon_discount' => 0,
                'taxable_base' => $calc['taxable_base'],
                'tax' => $calc['tax'],
                'shipping' => 0,
                'rounding' => $calc['rounding'],
                'grand_total' => $grand,
                'paid_amount' => 0,
                'due_amount' => $grand,
                'tax_applicable' => (bool) $invoice->tax_applicable,
                'tax_code' => $invoice->tax_code,
                'printed_title' => $invoiceDocType->printed_title,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($priced as $i => $line) {
                $lineNo++;
                $c = $calc['lines'][$i];

                InvoiceLine::create([
                    'company_id' => $companyId,
                    'invoice_id' => $newInvoice->id,
                    'line_no' => $i + 1,
                    'product_id' => $line['product_id'],
                    'sales_order_line_id' => null,
                    'description' => $line['description'],
                    'qty' => number_format($c['qty'], 4, '.', ''),
                    'unit_price' => number_format($c['unit_price'], 4, '.', ''),
                    'discount' => number_format($c['discount'], 4, '.', ''),
                    'tax' => number_format($c['tax'], 4, '.', ''),
                    'line_total' => number_format($c['line_total'], 4, '.', ''),
                ]);

                ExchangeLine::create([
                    'company_id' => $companyId,
                    'exchange_id' => $exchange->id,
                    'direction' => 'issue',
                    'line_no' => $lineNo,
                    'invoice_line_id' => null,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'qty' => number_format($c['qty'], 4, '.', ''),
                    'unit_price' => number_format($c['unit_price'], 4, '.', ''),
                    'tax' => number_format($c['tax'], 4, '.', ''),
                    'line_total' => number_format($c['line_total'], 4, '.', ''),
                ]);
            }

            // Issue leg STK out + GL Dr ar / Cr sales — the shared issue path.
            $this->issueInvoice->handle($newInvoice->fresh('lines'), $request);
            $newInvoice = $newInvoice->fresh();

            $exchangeTotal = round((float) $newInvoice->grand_total, 4);
            $differential = round($exchangeTotal - $returnTotal, 4);

            // ---- ACCT differential part 1: credit the returned goods.
            if ($returnTotal > 0) {
                $this->postReturnCredit($exchange, $invoice, $returnTotal, $returnTax, $request);
            }

            // ---- ACCT differential part 2: settle the signed difference.
            $payment = null;

            if ($differential > 0) {
                $payment = $this->recordPayment->handle([
                    'invoice_id' => $newInvoice->id,
                    'amount' => $differential,
                    'method' => $method,
                    'narration' => "POS exchange {$exchangeNo} difference",
                    'idempotency_key' => 'pos-exchange-pay:'.$exchange->id,
                ], $request);
            } elseif ($differential < 0) {
                $this->postRefund($exchange, $invoice, abs($differential), $method, $request);
            }

            // The residual after any cash collection is settled by the goods
            // credit posted above: paid/due reflect the full settlement while
            // payment rows stay the real money that moved.
            if ($returnTotal > 0 || $exchangeTotal <= 0.0001) {
                $newInvoice->paid_amount = number_format($exchangeTotal, 4, '.', '');
                $newInvoice->due_amount = number_format(0, 4, '.', '');
                $newInvoice->status = 'paid';
                $newInvoice->save();
            }

            // ---- Drawer: the difference is the money that moved at the counter.
            if ($differential > 0) {
                PosTransaction::create([
                    'company_id' => $companyId,
                    'pos_session_id' => $session->id,
                    'invoice_id' => $newInvoice->id,
                    'customer_id' => $invoice->customer_id,
                    'client_uuid' => null,
                    'status' => 'completed',
                    'sync_state' => 'synced',
                    'total' => number_format($differential, 4, '.', ''),
                    'payment_method' => $method,
                    'tendered' => number_format($differential, 4, '.', ''),
                    'change_due' => number_format(0, 4, '.', ''),
                    'sold_at' => now(),
                    'created_by' => $request->user()->id,
                ]);

                if ($method === 'cash') {
                    $session->cash_sales = number_format((float) $session->cash_sales + $differential, 4, '.', '');
                } else {
                    $session->non_cash_sales = number_format((float) $session->non_cash_sales + $differential, 4, '.', '');
                }
            } elseif ($differential < 0 && $method === 'cash') {
                $session->cash_out = number_format((float) $session->cash_out + abs($differential), 4, '.', '');
            }
            $session->save();

            $exchange->new_invoice_id = $newInvoice->id;
            $exchange->exchange_total = number_format($exchangeTotal, 4, '.', '');
            $exchange->price_differential = number_format($differential, 4, '.', '');
            $exchange->save();

            $this->audit->record([
                'action' => 'pos.exchange_processed',
                'entity_type' => 'exchange',
                'entity_id' => $exchange->id,
                'actor_id' => $request->user()->id,
                'after' => [
                    'exchange_no' => $exchangeNo,
                    'invoice_no' => $invoice->invoice_no,
                    'new_invoice_no' => $newInvoice->invoice_no,
                    'return_total' => $returnTotal,
                    'exchange_total' => $exchangeTotal,
                    'price_differential' => $differential,
                    'method' => $method,
                ],
            ]);

            return [
                'exchange' => $exchange->fresh('lines'),
                'invoice' => $newInvoice,
                'payment' => $payment,
                'session' => $session->fresh(),
            ];
        });
    }

    /**
     * Dr sales (+ tax) / Cr ar for the returned goods — the mirror of the
     * new invoice's own entry, so the ledger nets the exchange to zero.
     */
    protected function postReturnCredit(Exchange $exchange, Invoice $invoice, float $returnTotal, float $returnTax, Request $request): void
    {
        $resolved = $this->rules->resolve('sales_credit_note_issued', 'credit_note');
        $byRole = [];
        foreach ($resolved as $r) {
            $byRole[$r['role']] = $r;
        }

        $net = round($returnTotal - $returnTax, 4);
        $lines = [];
        if (isset($byRole['sales']) && $net > 0) {
            $lines[] = [
                'account_id' => $byRole['sales']['account']->id,
                'dc' => 'debit',
                'amount' => $net,
            ];
        }
        if (isset($byRole['tax_payable']) && $returnTax > 0) {
            $lines[] = [
                'account_id' => $byRole['tax_payable']['account']->id,
                'dc' => 'debit',
                'amount' => $returnTax,
            ];
        }
        if (isset($byRole['ar'])) {
            $lines[] = [
                'account_id' => $byRole['ar']['account']->id,
                'dc' => 'credit',
                'amount' => $returnTotal,
                'party_type' => $invoice->customer_id ? 'customer' : null,
                'party_id' => $invoice->customer_id,
            ];
        }

        if (count($lines) < 2) {
            throw new RuntimeException('sales_credit_note_issued posting rule is not configured (sales/tax/ar roles).');
        }

        $this->posting->post([
            'entry_date' => now()->toDateString(),
            'description' => sprintf('Exchange %s return leg against %s', $exchange->exchange_no, $invoice->invoice_no),
            'journal_type' => 'sales',
            'source_type' => 'exchange',
            'source_id' => $exchange->id,
            'source_event' => 'sales_credit_note_issued',
            'branch_id' => $invoice->branch_id,
            'lines' => $lines,
        ], $request->user());
    }

    /** D < 0: the counter pays the customer back — Dr ar / Cr cash (refund rule). */
    protected function postRefund(Exchange $exchange, Invoice $invoice, float $amount, string $method, Request $request): void
    {
        $resolved = $this->rules->resolve('refund');
        $byRole = [];
        foreach ($resolved as $r) {
            $byRole[$r['role']] = $r;
        }

        $creditAccount = null;
        if ($method === 'bank' && isset($byRole['bank'])) {
            $creditAccount = $byRole['bank']['account'];
        } elseif (isset($byRole['cash'])) {
            $creditAccount = $byRole['cash']['account'];
        }

        if (! isset($byRole['ar']) || $creditAccount === null) {
            throw new RuntimeException('refund posting rule is not configured (ar/cash roles).');
        }

        $this->posting->post([
            'entry_date' => now()->toDateString(),
            'description' => sprintf('Exchange %s refund %s against %s', $exchange->exchange_no, number_format($amount, 4, '.', ''), $invoice->invoice_no),
            'journal_type' => 'receipt',
            'source_type' => 'exchange',
            'source_id' => $exchange->id,
            'source_event' => 'refund',
            'branch_id' => $invoice->branch_id,
            'lines' => [
                [
                    'account_id' => $byRole['ar']['account']->id,
                    'dc' => 'debit',
                    'amount' => $amount,
                    'party_type' => $invoice->customer_id ? 'customer' : null,
                    'party_id' => $invoice->customer_id,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'dc' => 'credit',
                    'amount' => $amount,
                ],
            ],
        ], $request->user());
    }

    /** The counter exchange always settles through the branch's open drawer. */
    protected function requireOpenSession(array $payload, int $companyId, Request $request): PosSession
    {
        $sessionId = (int) ($payload['pos_session_id'] ?? 0);
        $query = PosSession::query()
            ->where('company_id', $companyId)
            ->where('status', 'open')
            ->lockForUpdate();

        $session = $sessionId > 0
            ? $query->whereKey($sessionId)->first()
            : $query->where('branch_id', $request->user()->default_branch_id)->first();

        if ($session === null) {
            throw new RuntimeException('No open POS session for this branch.');
        }

        return $session;
    }
}
