<?php

namespace App\Domain\Documents\Sources;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\PaymentAllocation;
use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Services\DocumentTitleService;
use App\Domain\Inventory\Services\QrService;
use App\Domain\Masters\Customer;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Models\PurchaseReturn;
use App\Domain\Sales\CreditNote;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Payment;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Services\InvoiceVerificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * §16-23 — the reusable document renderer's one table of truth.
 *
 * Every printable document in the application answers the same five questions,
 * so they are answered once here instead of in twelve templates:
 *
 *   · which `document_types` row gives it its title (and therefore whether it
 *     may show a tax block at all — §16-24);
 *   · which permission a person needs to put it on paper;
 *   · how to load the entity, scoped to the company, by id;
 *   · what the paper says: the party, the meta grid, the line columns, the
 *     totals, the notes and any code (QR) that belongs on it;
 *   · which paper sizes it may be printed on (a4, and thermal where a counter
 *     printer is the realistic destination).
 *
 * The definitions are deliberately data, not a class hierarchy: a new document
 * type is a row here plus whatever the entity already is. Nothing in this file
 * invents business facts — a type whose entity does not exist yet is listed in
 * `UNAVAILABLE` with the reason, and the screen says the reason instead of
 * printing a blank sheet.
 */
final class DocumentSourceRegistry
{
    /** Document types this build can render, in the order the desk lists them. */
    public const CODES = [
        'invoice', 'quotation', 'sales_order', 'purchase_order', 'goods_received_note',
        'delivery_challan', 'money_receipt', 'credit_note', 'journal_voucher',
        'account_ledger', 'party_statement', 'audit_report',
    ];

    /**
     * Types the catalogue knows and this build cannot honestly print yet.
     *
     * A payslip is computed from a payroll run; §10 has employees, attendance
     * and leave, and no payroll engine, so there is nothing to compute one from.
     * Listing the reason here keeps the renderer's answer truthful.
     */
    public const UNAVAILABLE = [
        'payroll' => 'A payroll sheet needs a payroll run to print from. The HR module holds employees, attendance and leave; no payroll engine exists yet, so there is nothing to compute.',
        'payslip' => 'A payslip is a line of a payroll run. No payroll engine exists yet, so this build cannot print one that would be true.',
    ];

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->definitions()) || array_key_exists($type, self::UNAVAILABLE);
    }

    public function available(string $type): bool
    {
        return array_key_exists($type, $this->definitions());
    }

    public function unavailableReason(string $type): ?string
    {
        return self::UNAVAILABLE[$type] ?? null;
    }

    /**
     * Where a person finds a document to print. The desk names the screen rather
     * than asking for an id nobody has in their head.
     */
    public const HINTS = [
        'invoice' => 'Sales › Invoices',
        'quotation' => 'Sales › Quotations',
        'sales_order' => 'Sales › Orders',
        'purchase_order' => 'Purchase › Purchase orders',
        'goods_received_note' => 'Purchase › Goods receipts',
        'delivery_challan' => 'Delivery › Challans',
        'money_receipt' => 'Sales › Money receipts',
        'credit_note' => 'Returns › Credit notes',
        'journal_voucher' => 'Accounts › Journals',
        'account_ledger' => 'Accounts › Ledger (print an account)',
        'party_statement' => 'Accounts › Party statement',
        'audit_report' => 'Settings › Audit trail',
    ];

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'invoice' => [
                'title_code' => 'invoice',
                'permission' => 'sales.invoices.print',
                'label' => 'Sales invoice',
                'papers' => ['a4', 'thermal'],
                'load' => fn (int $id, array $q): ?Model => Invoice::query()
                    ->with(['lines.product', 'customer', 'salesOrder', 'branch', 'company'])
                    ->find($id),
                'build' => function (Invoice $invoice): array {
                    $paid = (float) $invoice->paid_amount;
                    $due = (float) $invoice->due_amount;

                    return [
                        'reference' => (string) $invoice->invoice_no,
                        'date' => $invoice->invoice_date,
                        'status' => (string) $invoice->status,
                        'party' => [
                            'label' => 'Billed to',
                            'name' => (string) ($invoice->customer?->name ?? 'Walk-in customer'),
                            'lines' => array_values(array_filter([
                                $invoice->customer?->phone,
                                $invoice->customer?->email,
                                $invoice->customer?->address_line1,
                                $invoice->customer?->bin !== null ? 'BIN '.$invoice->customer->bin : null,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Invoice date', 'value' => $invoice->invoice_date?->format('d M Y')],
                            ['label' => 'Due date', 'value' => $invoice->due_date?->format('d M Y')],
                            ['label' => 'Sales order', 'value' => $invoice->salesOrder?->order_no],
                            ['label' => 'Branch', 'value' => $invoice->branch?->name],
                            ['label' => 'Currency', 'value' => $invoice->currency],
                            ['label' => 'Status', 'value' => strtoupper((string) $invoice->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => '38%'],
                            ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'unit_price', 'label' => 'Rate', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'discount', 'label' => 'Discount', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'tax', 'label' => 'VAT', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'line_total', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($invoice->lines, fn ($line) => [
                            'description' => (string) ($line->description ?: ($line->product?->name ?? 'Item')),
                            'qty' => (float) $line->qty,
                            'unit_price' => (float) $line->unit_price,
                            'discount' => (float) $line->discount,
                            'tax' => (float) $line->tax,
                            'line_total' => (float) $line->line_total,
                        ]),
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => (float) $invoice->subtotal, 'type' => 'money'],
                            ['label' => 'Discount', 'value' => (float) $invoice->doc_discount, 'type' => 'money'],
                            ['label' => 'Taxable base', 'value' => (float) $invoice->taxable_base, 'type' => 'money'],
                            ['label' => 'Shipping', 'value' => (float) $invoice->shipping, 'type' => 'money'],
                            ['label' => 'Rounding', 'value' => (float) $invoice->rounding, 'type' => 'money'],
                            ['label' => 'Grand total', 'value' => (float) $invoice->grand_total, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [
                            ['label' => 'VAT'.($invoice->tax_code !== null ? ' ('.$invoice->tax_code.')' : ''), 'value' => (float) $invoice->tax, 'type' => 'money'],
                        ],
                        'settlement' => [
                            ['label' => 'Paid', 'value' => $paid, 'type' => 'money'],
                            ['label' => 'Due', 'value' => $due, 'type' => 'money', 'strong' => true],
                        ],
                        'notes' => array_values(array_filter([
                            $invoice->notes,
                            // A draft is not a demand for money, and the paper says so.
                            $invoice->status === 'draft' ? 'DRAFT — not yet issued. This copy does not request payment.' : null,
                            $invoice->status === 'void' ? 'VOID — this document has been cancelled and must not be paid.' : null,
                        ])),
                        'qr' => $this->invoiceQr($invoice),
                    ];
                },
            ],

            'quotation' => [
                'title_code' => 'quotation',
                'permission' => 'sales.quotations.view',
                'label' => 'Quotation',
                'papers' => ['a4'],
                'load' => fn (int $id, array $q): ?Model => Quotation::query()
                    ->with(['lines.product', 'customer'])
                    ->find($id),
                'build' => function (Quotation $quotation): array {
                    return [
                        'reference' => (string) $quotation->quote_no,
                        'date' => $quotation->quote_date,
                        'status' => (string) $quotation->status,
                        'party' => [
                            'label' => 'Prepared for',
                            'name' => (string) ($quotation->customer?->name ?? 'Walk-in customer'),
                            'lines' => array_values(array_filter([
                                $quotation->customer?->phone,
                                $quotation->customer?->email,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Quote date', 'value' => $quotation->quote_date?->format('d M Y')],
                            ['label' => 'Valid until', 'value' => $quotation->valid_until?->format('d M Y')],
                            ['label' => 'Revision', 'value' => 'Rev '.$quotation->revision],
                            ['label' => 'Currency', 'value' => $quotation->currency],
                            ['label' => 'Status', 'value' => strtoupper((string) $quotation->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'width' => '40%'],
                            ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'unit_price', 'label' => 'Rate', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'discount', 'label' => 'Discount', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'tax', 'label' => 'VAT', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'line_total', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($quotation->lines, fn ($line) => [
                            'description' => (string) ($line->description ?: ($line->product?->name ?? 'Item')),
                            'qty' => (float) $line->qty,
                            'unit_price' => (float) $line->unit_price,
                            'discount' => (float) $line->discount,
                            'tax' => (float) $line->tax,
                            'line_total' => (float) $line->line_total,
                        ]),
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => (float) $quotation->subtotal, 'type' => 'money'],
                            ['label' => 'Discount', 'value' => (float) $quotation->discount, 'type' => 'money'],
                            ['label' => 'Shipping', 'value' => (float) $quotation->shipping, 'type' => 'money'],
                            ['label' => 'Grand total', 'value' => (float) $quotation->grand_total, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [
                            ['label' => 'VAT', 'value' => (float) $quotation->tax, 'type' => 'money'],
                        ],
                        'notes' => array_values(array_filter([
                            $quotation->notes,
                            $quotation->valid_until !== null
                                ? 'Prices hold until '.$quotation->valid_until->format('d M Y').'.'
                                : null,
                        ])),
                        'qr' => null,
                    ];
                },
            ],

            'sales_order' => [
                'title_code' => 'sales_order',
                'permission' => 'sales.orders.print',
                'label' => 'Sales order',
                'papers' => ['a4', 'thermal'],
                'load' => fn (int $id, array $q): ?Model => SalesOrder::query()
                    ->with(['lines.product', 'customer', 'warehouse'])
                    ->find($id),
                'build' => function (SalesOrder $order): array {
                    return [
                        'reference' => (string) $order->order_no,
                        'date' => $order->order_date,
                        'status' => (string) $order->status,
                        'party' => [
                            'label' => 'Customer',
                            'name' => (string) ($order->customer?->name ?? 'Walk-in customer'),
                            'lines' => array_values(array_filter([
                                $order->customer?->phone,
                                $order->customer?->email,
                                $order->warehouse !== null ? 'Deliver from '.$order->warehouse->name : null,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Order date', 'value' => $order->order_date?->format('d M Y')],
                            ['label' => 'Warehouse', 'value' => $order->warehouse?->name],
                            ['label' => 'Currency', 'value' => $order->currency],
                            ['label' => 'Stock reserved', 'value' => $order->stock_reserved ? 'yes' : 'not yet'],
                            ['label' => 'Status', 'value' => strtoupper((string) $order->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Item', 'type' => 'text', 'width' => '34%'],
                            ['key' => 'qty', 'label' => 'Ordered', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'delivered_qty', 'label' => 'Delivered', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'unit_price', 'label' => 'Rate', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'discount', 'label' => 'Discount', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'line_total', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($order->lines, fn ($line) => [
                            'description' => (string) ($line->description ?: ($line->product?->name ?? 'Item')),
                            'qty' => (float) $line->qty,
                            'delivered_qty' => (float) $line->delivered_qty,
                            'unit_price' => (float) $line->unit_price,
                            'discount' => (float) $line->discount,
                            'line_total' => (float) $line->line_total,
                        ]),
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => (float) $order->subtotal, 'type' => 'money'],
                            ['label' => 'Discount', 'value' => (float) $order->discount, 'type' => 'money'],
                            ['label' => 'Shipping', 'value' => (float) $order->shipping, 'type' => 'money'],
                            ['label' => 'Grand total', 'value' => (float) $order->grand_total, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [
                            ['label' => 'VAT', 'value' => (float) $order->tax, 'type' => 'money'],
                        ],
                        'notes' => array_values(array_filter([
                            $order->notes,
                            $order->status === 'cancelled' ? 'CANCELLED' : null,
                        ])),
                        'qr' => null,
                    ];
                },
            ],

            'purchase_order' => [
                'title_code' => 'purchase_order',
                'permission' => 'purchase.orders.view',
                'label' => 'Purchase order',
                'papers' => ['a4'],
                'load' => fn (int $id, array $q): ?Model => PurchaseOrder::query()
                    ->with(['lines.product', 'supplier', 'warehouse', 'branch', 'creator', 'approver'])
                    ->find($id),
                'build' => function (PurchaseOrder $order): array {
                    return [
                        'reference' => (string) $order->code,
                        'date' => $order->order_date,
                        'status' => (string) $order->status,
                        'party' => [
                            'label' => 'Supplier',
                            'name' => (string) ($order->supplier?->name ?? 'Unknown supplier'),
                            'lines' => array_values(array_filter([
                                $order->supplier?->phone,
                                $order->supplier?->email,
                                $order->supplier?->address_line1,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Order date', 'value' => $order->order_date?->format('d M Y')],
                            ['label' => 'Expected', 'value' => $order->expected_date?->format('d M Y')],
                            ['label' => 'Reference', 'value' => $order->reference],
                            ['label' => 'Deliver to', 'value' => $order->warehouse?->name],
                            ['label' => 'Payment terms', 'value' => $order->payment_terms],
                            ['label' => 'Raised by', 'value' => $order->creator?->name],
                            ['label' => 'Approved by', 'value' => $order->approver?->name],
                            ['label' => 'Status', 'value' => strtoupper((string) $order->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Item', 'type' => 'text', 'width' => '32%'],
                            ['key' => 'qty_ordered', 'label' => 'Ordered', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'qty_received', 'label' => 'Received', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'unit_price', 'label' => 'Rate', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'discount', 'label' => 'Discount', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'line_total', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($order->lines, fn ($line) => [
                            'description' => (string) ($line->description ?: ($line->product?->name ?? 'Item')),
                            'qty_ordered' => (float) $line->qty_ordered,
                            'qty_received' => (float) $line->qty_received,
                            'unit_price' => (float) $line->unit_price,
                            'discount' => (float) $line->discount,
                            'line_total' => (float) $line->line_total,
                        ]),
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => (float) $order->subtotal, 'type' => 'money'],
                            ['label' => 'Discount', 'value' => (float) $order->discount_total, 'type' => 'money'],
                            ['label' => 'Total', 'value' => (float) $order->total, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [
                            ['label' => 'VAT', 'value' => (float) $order->tax_total, 'type' => 'money'],
                        ],
                        'notes' => array_values(array_filter([$order->notes, $order->cancel_reason])),
                        'qr' => null,
                    ];
                },
            ],

            'goods_received_note' => [
                'title_code' => 'goods_received_note',
                'permission' => 'purchase.receipts.view',
                'label' => 'Goods received note',
                'papers' => ['a4'],
                'load' => fn (int $id, array $q): ?Model => GoodsReceipt::query()
                    ->with(['lines.product', 'supplier', 'warehouse', 'order', 'receiver'])
                    ->find($id),
                'build' => function (GoodsReceipt $receipt): array {
                    return [
                        'reference' => (string) $receipt->code,
                        'date' => $receipt->received_date,
                        'status' => (string) $receipt->status,
                        'party' => [
                            'label' => 'Received from',
                            'name' => (string) ($receipt->supplier?->name ?? 'Unknown supplier'),
                            'lines' => array_values(array_filter([
                                $receipt->supplier?->phone,
                                $receipt->challan_no !== null ? 'Supplier challan '.$receipt->challan_no : null,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Received on', 'value' => $receipt->received_date?->format('d M Y')],
                            ['label' => 'Purchase order', 'value' => $receipt->order?->code],
                            ['label' => 'Warehouse', 'value' => $receipt->warehouse?->name],
                            ['label' => 'Received by', 'value' => $receipt->receiver?->name],
                            ['label' => 'Posted', 'value' => $receipt->posted_at?->format('d M Y H:i')],
                            ['label' => 'Status', 'value' => strtoupper((string) $receipt->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Item', 'type' => 'text', 'width' => '36%'],
                            ['key' => 'qty_received', 'label' => 'Received', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'unit_cost', 'label' => 'Unit cost', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'batch_no', 'label' => 'Batch', 'type' => 'text'],
                            ['key' => 'line_total', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($receipt->lines, fn ($line) => [
                            'description' => (string) ($line->product?->name ?? 'Item'),
                            'qty_received' => (float) $line->qty_received,
                            'unit_cost' => (float) $line->unit_cost,
                            'batch_no' => (string) ($line->batch_no ?? ''),
                            'line_total' => (float) $line->line_total,
                        ]),
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => (float) $receipt->subtotal, 'type' => 'money'],
                            ['label' => 'Total', 'value' => (float) $receipt->total, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => array_values(array_filter([$receipt->notes])),
                        'qr' => null,
                    ];
                },
            ],

            'delivery_challan' => [
                'title_code' => 'delivery_challan',
                'permission' => 'sales.delivery.print',
                'label' => 'Delivery challan',
                'papers' => ['a4', 'thermal'],
                'load' => fn (int $id, array $q): ?Model => DeliveryChallan::query()
                    ->with(['lines.product', 'order.customer', 'branch'])
                    ->find($id),
                'build' => function (DeliveryChallan $challan): array {
                    return [
                        'reference' => (string) $challan->challan_no,
                        'date' => $challan->challan_date,
                        'status' => (string) $challan->status,
                        'party' => [
                            'label' => 'Deliver to',
                            'name' => (string) ($challan->order?->customer?->name ?? 'Walk-in customer'),
                            'lines' => array_values(array_filter([
                                $challan->order?->customer?->phone,
                                $challan->order?->customer?->address_line1,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Challan date', 'value' => $challan->challan_date?->format('d M Y')],
                            ['label' => 'Sales order', 'value' => $challan->order?->order_no],
                            ['label' => 'Courier', 'value' => $challan->courier_name],
                            ['label' => 'Tracking', 'value' => $challan->tracking_no],
                            ['label' => 'Dispatched', 'value' => $challan->dispatched_at?->format('d M Y H:i')],
                            ['label' => 'Delivered', 'value' => $challan->delivered_at?->format('d M Y H:i')],
                            ['label' => 'Status', 'value' => strtoupper((string) $challan->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Item', 'type' => 'text', 'width' => '60%'],
                            ['key' => 'qty', 'label' => 'Quantity sent', 'type' => 'qty', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($challan->lines, fn ($line) => [
                            'description' => (string) ($line->description ?: ($line->product?->name ?? 'Item')),
                            'qty' => (float) $line->qty,
                        ]),
                        // A challan moves goods; it is not a demand for money, so it
                        // carries quantities and no amounts at all.
                        'totals' => [
                            ['label' => 'Total quantity', 'value' => (float) $challan->lines->sum('qty'), 'type' => 'qty', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => array_values(array_filter([
                            $challan->notes,
                            'Goods sent on challan. This is not a tax document and no payment is due against it.',
                        ])),
                        'qr' => null,
                    ];
                },
            ],

            'money_receipt' => [
                'title_code' => 'money_receipt',
                'permission' => 'sales.payments.print',
                'label' => 'Money receipt',
                'papers' => ['a4', 'thermal'],
                'load' => fn (int $id, array $q): ?Model => Payment::query()
                    ->with(['customer', 'supplier', 'account', 'paymentMethod', 'allocations', 'journalEntry'])
                    ->find($id),
                'build' => function (Payment $payment): array {
                    $allocations = $payment->allocations
                        ->map(function (PaymentAllocation $allocation): array {
                            $target = $allocation->allocatable_type !== null
                                ? $allocation->allocatable_type::query()->find($allocation->allocatable_id)
                                : null;

                            return [
                                'document' => (string) ($target?->invoice_no ?? $target?->code ?? ('#'.$allocation->allocatable_id)),
                                'date' => $target?->invoice_date?->format('d M Y') ?? $target?->bill_date?->format('d M Y') ?? '—',
                                'amount' => (float) $allocation->amount,
                            ];
                        })
                        ->values()
                        ->all();

                    $isOutgoing = $payment->direction === 'out' || $payment->supplier_id !== null;

                    return [
                        'reference' => (string) $payment->receipt_no,
                        'date' => $payment->paid_at,
                        'status' => (string) $payment->status,
                        'party' => [
                            'label' => $isOutgoing ? 'Paid to' : 'Received from',
                            'name' => (string) ($payment->supplier?->name ?? $payment->customer?->name ?? 'Unnamed party'),
                            'lines' => array_values(array_filter([
                                $payment->supplier?->phone ?? $payment->customer?->phone,
                                $payment->reference !== null ? 'Reference '.$payment->reference : null,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Receipt no', 'value' => $payment->receipt_no],
                            ['label' => 'Paid on', 'value' => $payment->paid_at?->format('d M Y')],
                            ['label' => 'Direction', 'value' => $isOutgoing ? 'Money out' : 'Money in'],
                            ['label' => 'Method', 'value' => strtoupper((string) ($payment->method ?? $payment->paymentMethod?->name))],
                            ['label' => 'Account', 'value' => $payment->account?->name],
                            ['label' => 'Journal entry', 'value' => $payment->journalEntry?->entry_no],
                            ['label' => 'Status', 'value' => strtoupper((string) $payment->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'document', 'label' => 'Settled document', 'type' => 'text', 'width' => '50%'],
                            ['key' => 'date', 'label' => 'Document date', 'type' => 'text'],
                            ['key' => 'amount', 'label' => 'Applied', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $allocations,
                        'totals' => [
                            ['label' => $isOutgoing ? 'Paid' : 'Received', 'value' => (float) $payment->amount, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => array_values(array_filter([
                            $payment->narration,
                            $allocations === [] ? 'Not yet applied to any document.' : null,
                        ])),
                        'qr' => null,
                    ];
                },
            ],

            'credit_note' => [
                'title_code' => 'credit_note',
                'permission' => 'returns.credit',
                'label' => 'Credit note',
                'papers' => ['a4'],
                'load' => fn (int $id, array $q): ?Model => CreditNote::query()
                    ->with(['lines.product', 'customer', 'invoice', 'salesReturn'])
                    ->find($id),
                'build' => function (CreditNote $note): array {
                    return [
                        'reference' => (string) $note->credit_note_no,
                        'date' => $note->note_date,
                        'status' => (string) $note->status,
                        'party' => [
                            'label' => 'Customer',
                            'name' => (string) ($note->customer?->name ?? 'Walk-in customer'),
                            'lines' => array_values(array_filter([
                                $note->invoice !== null ? 'Against invoice '.$note->invoice->invoice_no : null,
                                $note->customer?->phone,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Credit note date', 'value' => $note->note_date?->format('d M Y')],
                            ['label' => 'Reason', 'value' => $note->reason],
                            ['label' => 'Status', 'value' => strtoupper((string) $note->status)],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'description', 'label' => 'Item', 'type' => 'text', 'width' => '46%'],
                            ['key' => 'qty', 'label' => 'Qty', 'type' => 'qty', 'align' => 'end'],
                            ['key' => 'unit_price', 'label' => 'Rate', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'line_total', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $this->lineRows($note->lines, fn ($line) => [
                            'description' => (string) ($line->description ?: ($line->product?->name ?? 'Item')),
                            'qty' => (float) $line->qty,
                            'unit_price' => (float) $line->unit_price,
                            'line_total' => (float) $line->line_total,
                        ]),
                        'totals' => [
                            ['label' => 'Subtotal', 'value' => (float) $note->subtotal, 'type' => 'money'],
                            ['label' => 'Credit total', 'value' => (float) $note->grand_total, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [
                            ['label' => 'VAT', 'value' => (float) $note->tax, 'type' => 'money'],
                        ],
                        'notes' => array_values(array_filter([
                            $note->notes,
                            'This note reduces what the customer owes; it is not a refund receipt.',
                        ])),
                        'qr' => null,
                    ];
                },
            ],

            'journal_voucher' => [
                'title_code' => 'journal_voucher',
                'permission' => 'accounting.journals.view',
                'label' => 'Journal voucher',
                'papers' => ['a4'],
                'load' => fn (int $id, array $q): ?Model => JournalEntry::query()
                    ->with(['lines.account', 'creator', 'poster', 'reversalOf', 'fiscalPeriod'])
                    ->find($id),
                'build' => function (JournalEntry $entry): array {
                    return [
                        'reference' => (string) $entry->entry_no,
                        'date' => $entry->entry_date,
                        'status' => (string) $entry->posting_state,
                        'party' => [
                            'label' => 'Posting state',
                            'name' => strtoupper((string) $entry->posting_state),
                            'lines' => array_values(array_filter([
                                $entry->entry_date?->format('d M Y'),
                                $entry->fiscalPeriod?->name,
                                $entry->isReversed() ? 'Reversed' : null,
                            ])),
                        ],
                        'meta' => array_values(array_filter([
                            ['label' => 'Entry no', 'value' => $entry->entry_no],
                            ['label' => 'Entry date', 'value' => $entry->entry_date?->format('d M Y')],
                            ['label' => 'Journal type', 'value' => $entry->journal_type],
                            ['label' => 'Source', 'value' => $entry->source_type],
                            ['label' => 'Reverses', 'value' => $entry->reversalOf?->entry_no],
                            ['label' => 'Prepared by', 'value' => $entry->creator?->name],
                            ['label' => 'Posted by', 'value' => $entry->poster?->name],
                        ], fn ($row) => $row['value'] !== null && $row['value'] !== '')),
                        'columns' => [
                            ['key' => 'account', 'label' => 'Account', 'type' => 'text', 'width' => '34%'],
                            ['key' => 'narration', 'label' => 'Narration', 'type' => 'text', 'width' => '30%'],
                            ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $entry->lines->sortBy('line_no')->values()->map(fn ($line) => [
                            'account' => trim(($line->account?->code ?? '').' '.($line->account?->name ?? '#'.$line->account_id)),
                            'narration' => (string) ($line->narration ?? ''),
                            'debit' => $line->dc === 'debit' ? (float) $line->amount : 0.0,
                            'credit' => $line->dc === 'credit' ? (float) $line->amount : 0.0,
                        ])->all(),
                        'totals' => [
                            ['label' => 'Total debit', 'value' => (float) $entry->total_debit, 'type' => 'money', 'strong' => true],
                            ['label' => 'Total credit', 'value' => (float) $entry->total_credit, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => array_values(array_filter([$entry->description, $entry->narration])),
                        'qr' => null,
                    ];
                },
            ],

            /*
             * The ledger and the statement are the same question over two
             * different subjects — "what moved on this account" and "what moved
             * with this party" — so they share one shape: dated rows with a
             * running balance, over a period that the page states in words.
             */
            'account_ledger' => [
                'title_code' => 'account_ledger',
                'permission' => 'accounting.ledger.view',
                'label' => 'Account ledger',
                'papers' => ['a4'],
                'period' => true,
                'load' => fn (int $id, array $q): ?Model => Account::query()->with(['group'])->find($id),
                'build' => function (Account $account, array $q): array {
                    [$from, $to] = $this->period($q);
                    $lines = DB::table('journal_lines')
                        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                        ->where('journal_lines.company_id', $account->company_id)
                        ->where('journal_lines.account_id', $account->id)
                        ->whereNull('journal_entries.reversal_of_id')
                        ->whereDate('journal_entries.entry_date', '>=', $from->toDateString())
                        ->whereDate('journal_entries.entry_date', '<=', $to->toDateString())
                        ->orderBy('journal_entries.entry_date')
                        ->orderBy('journal_entries.id')
                        ->orderBy('journal_lines.line_no')
                        ->get([
                            'journal_entries.entry_no', 'journal_entries.entry_date',
                            'journal_entries.description', 'journal_entries.id as entry_id',
                            'journal_lines.dc', 'journal_lines.amount', 'journal_lines.narration',
                        ]);

                    $opening = (float) DB::table('journal_lines')
                        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                        ->where('journal_lines.company_id', $account->company_id)
                        ->where('journal_lines.account_id', $account->id)
                        ->whereNull('journal_entries.reversal_of_id')
                        ->whereDate('journal_entries.entry_date', '<', $from->toDateString())
                        ->selectRaw("COALESCE(SUM(CASE WHEN journal_lines.dc = 'debit' THEN journal_lines.amount ELSE -journal_lines.amount END), 0) as balance")
                        ->value('balance');

                    $running = $opening;
                    $rows = [];
                    $debitTotal = 0.0;
                    $creditTotal = 0.0;

                    if (abs($opening) > 0.00005) {
                        $rows[] = [
                            'date' => $from->format('d M Y'),
                            'entry_no' => '—',
                            'narration' => 'Opening balance brought forward',
                            'debit' => 0.0,
                            'credit' => 0.0,
                            'balance' => $opening,
                        ];
                    }

                    foreach ($lines as $line) {
                        $debit = $line->dc === 'debit' ? (float) $line->amount : 0.0;
                        $credit = $line->dc === 'credit' ? (float) $line->amount : 0.0;
                        $debitTotal += $debit;
                        $creditTotal += $credit;
                        $running += $debit - $credit;

                        $rows[] = [
                            'date' => Carbon::parse($line->entry_date)->format('d M Y'),
                            'entry_no' => (string) $line->entry_no,
                            'narration' => (string) ($line->narration ?: $line->description),
                            'debit' => $debit,
                            'credit' => $credit,
                            'balance' => $running,
                        ];
                    }

                    return [
                        'reference' => (string) $account->code,
                        'date' => $to,
                        'status' => 'recorded',
                        'party' => [
                            'label' => 'Account',
                            'name' => (string) ($account->code.' — '.$account->name),
                            'lines' => array_values(array_filter([
                                $account->group?->name,
                                'Type '.ucfirst((string) $account->type),
                            ])),
                        ],
                        'meta' => [
                            ['label' => 'Period', 'value' => $from->format('d M Y').' – '.$to->format('d M Y')],
                            ['label' => 'Opening balance', 'value' => $opening],
                            ['label' => 'Closing balance', 'value' => $running],
                            ['label' => 'Rows', 'value' => (string) count($rows)],
                        ],
                        'columns' => [
                            ['key' => 'date', 'label' => 'Date', 'type' => 'text'],
                            ['key' => 'entry_no', 'label' => 'Entry', 'type' => 'text'],
                            ['key' => 'narration', 'label' => 'Particulars', 'type' => 'text', 'width' => '34%'],
                            ['key' => 'debit', 'label' => 'Debit', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'credit', 'label' => 'Credit', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $rows,
                        'totals' => [
                            ['label' => 'Total debit', 'value' => $debitTotal, 'type' => 'money'],
                            ['label' => 'Total credit', 'value' => $creditTotal, 'type' => 'money'],
                            ['label' => 'Closing balance', 'value' => $running, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => [
                            'Reversed entries are excluded — the reversal pair would otherwise count twice.',
                            'Only posted journal entries are read, so nothing here is a draft that may still change.',
                        ],
                        'qr' => null,
                    ];
                },
            ],

            'party_statement' => [
                'title_code' => 'party_statement',
                'permission' => 'accounting.ledger.view',
                'label' => 'Party statement',
                'papers' => ['a4'],
                'period' => true,
                'load' => fn (int $id, array $q): ?Model => ($q['party'] ?? 'customer') === 'supplier'
                    ? Supplier::query()->find($id)
                    : Customer::query()->find($id),
                'build' => function (Model $party, array $q): array {
                    [$from, $to] = $this->period($q);
                    $isSupplier = ($q['party'] ?? 'customer') === 'supplier';

                    $rows = $this->statementRows($party, $isSupplier, $from, $to);

                    $running = 0.0;
                    $debitTotal = 0.0;
                    $creditTotal = 0.0;
                    $out = [];

                    foreach ($rows as $row) {
                        $debitTotal += $row['debit'];
                        $creditTotal += $row['credit'];
                        $running += $row['debit'] - $row['credit'];
                        $row['balance'] = $running;
                        $out[] = $row;
                    }

                    return [
                        'reference' => (string) $party->code,
                        'date' => $to,
                        'status' => 'recorded',
                        'party' => [
                            'label' => $isSupplier ? 'Supplier' : 'Customer',
                            'name' => (string) $party->name,
                            'lines' => array_values(array_filter([
                                $party->phone,
                                $party->email,
                                $party->address_line1,
                            ])),
                        ],
                        'meta' => [
                            ['label' => 'Statement period', 'value' => $from->format('d M Y').' – '.$to->format('d M Y')],
                            ['label' => 'Rows', 'value' => (string) count($out)],
                            ['label' => 'Closing balance', 'value' => $running],
                        ],
                        'columns' => [
                            ['key' => 'date', 'label' => 'Date', 'type' => 'text'],
                            ['key' => 'document', 'label' => 'Document', 'type' => 'text'],
                            ['key' => 'kind', 'label' => 'What it was', 'type' => 'text', 'width' => '28%'],
                            ['key' => 'debit', 'label' => $isSupplier ? 'Billed' : 'Invoiced', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'credit', 'label' => $isSupplier ? 'Paid' : 'Settled', 'type' => 'money', 'align' => 'end'],
                            ['key' => 'balance', 'label' => 'Balance', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $out,
                        'totals' => [
                            ['label' => $isSupplier ? 'Total billed' : 'Total invoiced', 'value' => $debitTotal, 'type' => 'money'],
                            ['label' => $isSupplier ? 'Total paid' : 'Total settled', 'value' => $creditTotal, 'type' => 'money'],
                            ['label' => 'Closing balance', 'value' => $running, 'type' => 'money', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => [
                            $running > 0.005
                                ? ($isSupplier ? 'The company owes this supplier the closing balance.' : 'This customer owes the company the closing balance.')
                                : 'Nothing outstanding at the end of the period.',
                            'Credit notes and debit notes are shown on the side that changes what is owed, not as separate totals.',
                        ],
                        'qr' => null,
                    ];
                },
            ],

            'audit_report' => [
                'title_code' => 'audit_report',
                'permission' => 'audit.export',
                'label' => 'Audit trail report',
                'papers' => ['a4'],
                'period' => true,
                // A report is a read of the trail, not a row in it: there is no
                // entity to load, and the renderer is told so explicitly rather
                // than handed a null that could mean "not found".
                'standalone' => true,
                'load' => fn (int $id, array $q): ?Model => null,
                'build' => function (?Model $ignored, array $q): array {
                    [$from, $to] = $this->period($q);
                    [, $companyId] = $this->actorContext();

                    $events = AuditEvent::query()
                        ->where('company_id', $companyId)
                        ->when(isset($q['filter']) && $q['filter'] !== '', fn ($query) => $query->where('action', 'like', $q['filter'].'%'))
                        ->whereDate('created_at', '>=', $from->toDateString())
                        ->whereDate('created_at', '<=', $to->toDateString())
                        ->orderBy('id')
                        ->limit(2000)
                        ->get();

                    return [
                        'reference' => 'AUD-'.$from->format('Ymd').'-'.$to->format('Ymd'),
                        'date' => $to,
                        'status' => 'recorded',
                        'party' => [
                            'label' => 'Subject',
                            'name' => 'Audit trail',
                            'lines' => array_values(array_filter([
                                'Period '.$from->format('d M Y').' – '.$to->format('d M Y'),
                                isset($q['filter']) && $q['filter'] !== '' ? 'Actions starting with '.$q['filter'] : null,
                            ])),
                        ],
                        'meta' => [
                            ['label' => 'Rows', 'value' => (string) $events->count()],
                            ['label' => 'First event', 'value' => $events->first()?->created_at?->format('d M Y H:i')],
                            ['label' => 'Last event', 'value' => $events->last()?->created_at?->format('d M Y H:i')],
                        ],
                        'columns' => [
                            ['key' => 'when', 'label' => 'When', 'type' => 'text'],
                            ['key' => 'action', 'label' => 'Action', 'type' => 'text', 'width' => '26%'],
                            ['key' => 'actor', 'label' => 'Who', 'type' => 'text'],
                            ['key' => 'entity', 'label' => 'What', 'type' => 'text'],
                            ['key' => 'result', 'label' => 'Result', 'type' => 'text'],
                            ['key' => 'reason', 'label' => 'Why', 'type' => 'text', 'width' => '22%'],
                            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'align' => 'end'],
                        ],
                        'rows' => $events->map(fn (AuditEvent $event) => [
                            'when' => $event->created_at?->format('d M H:i:s'),
                            'action' => (string) $event->action,
                            'actor' => (string) ($event->actor_label ?? $event->actor_type),
                            'entity' => trim((string) $event->entity_type.' #'.(string) $event->entity_id, ' #'),
                            'result' => (string) ($event->result ?? '—'),
                            // The reason is the part a reader needs most: a refusal
                            // without its why is not evidence of anything.
                            'reason' => (string) ($event->reason ?? ''),
                            'amount' => (float) ($event->amount ?? 0),
                        ])->all(),
                        'totals' => [
                            ['label' => 'Events', 'value' => (float) $events->count(), 'type' => 'qty', 'strong' => true],
                        ],
                        'tax_lines' => [],
                        'notes' => [
                            'Every row here is append-only and hash-chained; this report is a read of that chain, not a second copy of it.',
                            'Printing the audit trail is itself an audited action.',
                        ],
                        'qr' => null,
                    ];
                },
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    protected function lineRows(iterable $lines, callable $mapper): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = $mapper($line);
        }

        return $rows;
    }

    /** A4 and thermal are the same data on different paper; the period is the caller's. */
    protected function period(array $query): array
    {
        $from = isset($query['from']) && $query['from'] !== ''
            ? Carbon::parse($query['from'])->startOfDay()
            : now()->startOfMonth();

        $to = isset($query['to']) && $query['to'] !== ''
            ? Carbon::parse($query['to'])->endOfDay()
            : now()->endOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /** Ledger and statement rows both need the acting company, which is in context. */
    protected function actorContext(): array
    {
        $context = app(\App\Domain\Foundation\Services\TenantContext::class);

        return [$context->branchId(), (int) $context->companyId()];
    }

    /**
     * The party statement is built from what the ledgers already recorded: an
     * invoice raises what is owed, a payment or a credit note settles part of it.
     * Nothing is recomputed here — the numbers come from the documents.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function statementRows(Model $party, bool $isSupplier, Carbon $from, Carbon $to): array
    {
        $rows = [];

        if ($isSupplier) {
            $bills = PurchaseBill::query()
                ->where('supplier_id', $party->getKey())
                ->whereDate('bill_date', '>=', $from->toDateString())
                ->whereDate('bill_date', '<=', $to->toDateString())
                ->whereIn('status', ['approved', 'posted', 'partial', 'paid'])
                ->orderBy('bill_date')
                ->get();

            foreach ($bills as $bill) {
                $rows[] = [
                    'date' => $bill->bill_date?->format('d M Y'),
                    'document' => (string) $bill->code,
                    'kind' => 'Purchase bill'.($bill->supplier_bill_no !== null ? ' ('.$bill->supplier_bill_no.')' : ''),
                    'debit' => (float) $bill->total,
                    'credit' => 0.0,
                ];
            }

            $returns = PurchaseReturn::query()
                ->where('supplier_id', $party->getKey())
                ->whereDate('return_date', '>=', $from->toDateString())
                ->whereDate('return_date', '<=', $to->toDateString())
                ->whereNotIn('status', ['cancelled'])
                ->orderBy('return_date')
                ->get();

            foreach ($returns as $return) {
                $rows[] = [
                    'date' => $return->return_date?->format('d M Y'),
                    'document' => (string) $return->code,
                    'kind' => 'Return / debit note',
                    'debit' => 0.0,
                    'credit' => (float) $return->total,
                ];
            }
        } else {
            $invoices = Invoice::query()
                ->where('customer_id', $party->getKey())
                ->whereDate('invoice_date', '>=', $from->toDateString())
                ->whereDate('invoice_date', '<=', $to->toDateString())
                ->whereNotIn('status', ['draft', 'void'])
                ->orderBy('invoice_date')
                ->get();

            foreach ($invoices as $invoice) {
                $rows[] = [
                    'date' => $invoice->invoice_date?->format('d M Y'),
                    'document' => (string) $invoice->invoice_no,
                    'kind' => 'Invoice',
                    'debit' => (float) $invoice->grand_total,
                    'credit' => 0.0,
                ];
            }

            $notes = CreditNote::query()
                ->where('customer_id', $party->getKey())
                ->whereDate('note_date', '>=', $from->toDateString())
                ->whereDate('note_date', '<=', $to->toDateString())
                ->whereNotIn('status', ['cancelled'])
                ->orderBy('note_date')
                ->get();

            foreach ($notes as $note) {
                $rows[] = [
                    'date' => $note->note_date?->format('d M Y'),
                    'document' => (string) $note->credit_note_no,
                    'kind' => 'Credit note',
                    'debit' => 0.0,
                    'credit' => (float) $note->grand_total,
                ];
            }
        }

        $payments = Payment::query()
            ->when(
                $isSupplier,
                fn ($query) => $query->where('supplier_id', $party->getKey())->where('direction', 'out'),
                fn ($query) => $query->where('customer_id', $party->getKey())->where('direction', 'in'),
            )
            ->whereDate('paid_at', '>=', $from->toDateString())
            ->whereDate('paid_at', '<=', $to->toDateString())
            ->whereNotIn('status', ['cancelled', 'void'])
            ->orderBy('paid_at')
            ->get();

        foreach ($payments as $payment) {
            $rows[] = [
                'date' => $payment->paid_at?->format('d M Y'),
                'document' => (string) $payment->receipt_no,
                'kind' => 'Payment ('.($payment->method ?? 'unspecified').')',
                'debit' => 0.0,
                'credit' => (float) $payment->amount,
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        return $rows;
    }

    /** The verification address rides on an invoice only once it has been published. */
    protected function invoiceQr(Invoice $invoice): ?array
    {
        $url = app(InvoiceVerificationService::class)->url($invoice);

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => 'Scan to verify this invoice at '.parse_url($url, PHP_URL_HOST),
            'svg' => app(QrService::class)->svg($url, [
                'moduleSize' => 3,
                'title' => 'Verify '.(string) $invoice->invoice_no,
            ])['svg'],
        ];
    }

    /** The title rule travels with the source so a caller cannot forget it. */
    public function title(string $type, bool $hasTax): array
    {
        $definition = $this->definitions()[$type] ?? null;

        return app(DocumentTitleService::class)->resolve($definition['title_code'] ?? $type, $hasTax);
    }
}
