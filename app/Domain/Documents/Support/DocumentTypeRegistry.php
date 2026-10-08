<?php

namespace App\Domain\Documents\Support;

/**
 * Canonical document-type registry (spec section L + Rule 8/9).
 *
 * Used by the structural seeder AND by first-boot materialisation, so the
 * INVOICE printed title and the statutory Mushak rows can never drift
 * between code paths. This is STRUCTURE (what document kinds exist), not
 * fake business data — no rows reference real transactions here.
 */
final class DocumentTypeRegistry
{
    /**
     * @var array<int, array{code:string,type_group:string,name:string,printed_title:string,is_statutory?:bool,tax_applicable?:bool,requires_numbering?:bool}>
     */
    public const TYPES = [
        // ---- Sales family: the normal sales document prints as INVOICE (Rule 8)
        ['code' => 'invoice', 'type_group' => 'sales', 'name' => 'Sales Invoice', 'printed_title' => 'INVOICE', 'tax_applicable' => true],
        ['code' => 'quotation', 'type_group' => 'sales', 'name' => 'Quotation', 'printed_title' => 'QUOTATION'],
        ['code' => 'sales_order', 'type_group' => 'sales', 'name' => 'Sales Order', 'printed_title' => 'SALES ORDER'],
        ['code' => 'proforma_invoice', 'type_group' => 'sales', 'name' => 'Proforma Invoice', 'printed_title' => 'PROFORMA INVOICE', 'tax_applicable' => true],
        ['code' => 'delivery_challan', 'type_group' => 'sales', 'name' => 'Delivery Challan', 'printed_title' => 'DELIVERY CHALLAN'],
        ['code' => 'packing_slip', 'type_group' => 'sales', 'name' => 'Packing Slip', 'printed_title' => 'PACKING SLIP'],
        ['code' => 'shipping_label', 'type_group' => 'sales', 'name' => 'Shipping Label', 'printed_title' => 'SHIPPING LABEL'],
        ['code' => 'money_receipt', 'type_group' => 'sales', 'name' => 'Money Receipt', 'printed_title' => 'MONEY RECEIPT'],
        ['code' => 'credit_note', 'type_group' => 'sales', 'name' => 'Credit Note', 'printed_title' => 'CREDIT NOTE', 'tax_applicable' => true],
        ['code' => 'debit_note', 'type_group' => 'sales', 'name' => 'Debit Note', 'printed_title' => 'DEBIT NOTE', 'tax_applicable' => true],
        ['code' => 'exchange', 'type_group' => 'sales', 'name' => 'Exchange', 'printed_title' => 'EXCHANGE'],

        // ---- Purchase family (a purchase bill is NOT called an invoice on screen)
        ['code' => 'purchase_order', 'type_group' => 'purchase', 'name' => 'Purchase Order', 'printed_title' => 'PURCHASE ORDER'],
        ['code' => 'goods_received_note', 'type_group' => 'purchase', 'name' => 'Goods Received Note', 'printed_title' => 'GOODS RECEIVED NOTE'],
        ['code' => 'purchase_invoice', 'type_group' => 'purchase', 'name' => 'Purchase Bill', 'printed_title' => 'PURCHASE INVOICE', 'tax_applicable' => true],
        ['code' => 'supplier_payment_voucher', 'type_group' => 'purchase', 'name' => 'Supplier Payment', 'printed_title' => 'PAYMENT VOUCHER'],

        // ---- Inventory
        ['code' => 'stock_adjustment', 'type_group' => 'inventory', 'name' => 'Stock Adjustment', 'printed_title' => 'STOCK ADJUSTMENT'],
        ['code' => 'stock_transfer', 'type_group' => 'inventory', 'name' => 'Stock Transfer', 'printed_title' => 'STOCK TRANSFER'],
        ['code' => 'stock_damage_entry', 'type_group' => 'inventory', 'name' => 'Damage / Loss Entry', 'printed_title' => 'DAMAGE / LOSS REPORT'],
        ['code' => 'stock_write_off', 'type_group' => 'inventory', 'name' => 'Stock Write-Off', 'printed_title' => 'WRITE-OFF'],
        ['code' => 'stock_count', 'type_group' => 'inventory', 'name' => 'Stock Count Sheet', 'printed_title' => 'STOCK COUNT'],
        // §04-44: a pick list and a putaway list are numbered documents like any
        // other — the number is how the floor refers to the walk.
        ['code' => 'pick_list', 'type_group' => 'inventory', 'name' => 'Pick List', 'printed_title' => 'PICK LIST'],
        ['code' => 'putaway_list', 'type_group' => 'inventory', 'name' => 'Putaway List', 'printed_title' => 'PUTAWAY LIST'],
        // §04-56: a reorder suggestion is a numbered document too — the number is
        // how the desk and the purchase order it produced refer to each other.
        ['code' => 'reorder_suggestion', 'type_group' => 'inventory', 'name' => 'Reorder Suggestion', 'printed_title' => 'REORDER SUGGESTION'],

        // §04-53: a printed label sheet is a document like any other — stored,
        // checksummed and logged — but it carries no number of its own, because
        // nothing downstream ever refers to "label sheet 12". The codes that
        // appear on it belong to the things being labelled.
        ['code' => 'label_sheet', 'type_group' => 'inventory', 'name' => 'Label Sheet', 'printed_title' => 'LABELS', 'requires_numbering' => false],

        // ---- Accounting
        ['code' => 'journal_voucher', 'type_group' => 'accounting', 'name' => 'Journal Voucher', 'printed_title' => 'JOURNAL VOUCHER'],
        ['code' => 'expense_voucher', 'type_group' => 'accounting', 'name' => 'Expense Voucher', 'printed_title' => 'EXPENSE VOUCHER'],
        ['code' => 'payment_voucher', 'type_group' => 'accounting', 'name' => 'Payment Voucher', 'printed_title' => 'PAYMENT VOUCHER'],
        ['code' => 'receipt_voucher', 'type_group' => 'accounting', 'name' => 'Receipt Voucher', 'printed_title' => 'RECEIPT VOUCHER'],
        // §08-04: a transfer between two of the company's own accounts is asked
        // for by its number — "show me the 40,000 that moved on the 3rd" — so it
        // is a numbered document, not two loose journal lines.
        ['code' => 'cash_transfer', 'type_group' => 'accounting', 'name' => 'Cash Transfer', 'printed_title' => 'CASH TRANSFER'],
        // §08-14: the print-out of a cheque is filed, checksummed and logged
        // like every other generated paper — but it carries no number of its
        // own. The number that identifies a cheque is the one the bank printed
        // on the slip, and inventing a second one here would be the fastest way
        // to disagree with the bank statement.
        ['code' => 'cheque', 'type_group' => 'accounting', 'name' => 'Cheque', 'printed_title' => 'CHEQUE', 'requires_numbering' => false],

        // ---- HR
        ['code' => 'payroll', 'type_group' => 'hr', 'name' => 'Payroll Sheet', 'printed_title' => 'PAYROLL', 'tax_applicable' => true],
        ['code' => 'payslip', 'type_group' => 'hr', 'name' => 'Payslip', 'printed_title' => 'PAYSLIP', 'tax_applicable' => true],

        // ---- Statutory (SEPARATE types — never merged with 'invoice') (Rule 9)
        ['code' => 'mushak_9_1', 'type_group' => 'statutory', 'name' => 'Mushak 9.1 (VAT Challan)', 'printed_title' => 'MUSHAK 9.1', 'is_statutory' => true, 'tax_applicable' => true],
        ['code' => 'mushak_11', 'type_group' => 'statutory', 'name' => 'Mushak 11 (Monthly VAT Return)', 'printed_title' => 'MUSHAK 11', 'is_statutory' => true, 'tax_applicable' => true],
    ];

    /** Doc types that receive default numbering rules at first boot. */
    public const NUMBERING_PREFIXES = [
        'invoice' => 'INV',
        'sales_order' => 'SO',
        'quotation' => 'QT',
        'money_receipt' => 'MR',
        'delivery_challan' => 'DC',
        'shipping_label' => 'SL',
        'credit_note' => 'CN',
        'debit_note' => 'DN',
        'exchange' => 'EXC',
        'purchase_order' => 'PO',
        'journal_voucher' => 'JV',
        'expense_voucher' => 'EX',
        'cash_transfer' => 'CT',
        'stock_adjustment' => 'ADJ',
        'stock_transfer' => 'TRF',
        'stock_damage_entry' => 'DL',
        'stock_write_off' => 'WO',
        'stock_count' => 'SC',
        'pick_list' => 'PL',
        'putaway_list' => 'PA',
        'reorder_suggestion' => 'RS',
        'payroll' => 'PAY',
    ];

    /** @return array<string, array<string, mixed>> code => row attributes */
    public static function map(): array
    {
        $map = [];

        foreach (self::TYPES as $type) {
            $map[$type['code']] = [
                'code' => $type['code'],
                'type_group' => $type['type_group'],
                'name' => $type['name'],
                'printed_title' => $type['printed_title'],
                'is_statutory' => (bool) ($type['is_statutory'] ?? false),
                'tax_applicable' => (bool) ($type['tax_applicable'] ?? false),
                'requires_numbering' => (bool) ($type['requires_numbering'] ?? true),
                'is_active' => true,
            ];
        }

        return $map;
    }
}
