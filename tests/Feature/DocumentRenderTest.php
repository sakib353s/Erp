<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PrintHistory;
use App\Domain\Documents\Services\DocumentTitleService;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Invoice;
use Carbon\Carbon;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-23 / §16-24 / §16-25 — the reusable renderer, the title rule and the
 * history it leaves behind.
 *
 * What this file is here to hold down:
 *
 *  · **one shell, many documents** — an invoice, a quotation, an order, a
 *    purchase order, a goods receipt, a challan, a receipt, a credit note, a
 *    voucher, a ledger, a statement and the audit trail all render through the
 *    same page, and each carries its own title from the document-type rule;
 *  · **the title is a claim** — a normal sale says INVOICE, a statutory form
 *    says MUSHAK 9.1, and a non-statutory type may not borrow a tax title;
 *  · **a tax column that would be empty is not printed** (D10);
 *  · **every production is filed and recorded** — the checksum is the checksum
 *    of the bytes that were stored, the version climbs, and the history row
 *    carries the title, the paper, the copy locale, any watermark and the file;
 *  · **the doors are per document** — printing needs that document's key, and
 *    reading the print history needs its own.
 */
class DocumentRenderTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected int $customerId;

    protected int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-03-10 09:00:00'));

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);

        Storage::fake('local');

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = Product::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'DR-1',
            'sku' => 'DR-SKU-1',
            'name' => 'Rendered Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ]);

        $this->customerId = $this->insert('customers', [
            'company_id' => $this->admin->company_id,
            'code' => 'DR-CUST',
            'name' => 'Rendered Customer',
            'phone' => '01710000001',
            'is_active' => true,
        ]);

        $this->supplierId = $this->insert('suppliers', [
            'company_id' => $this->admin->company_id,
            'code' => 'DR-SUP',
            'name' => 'Rendered Supplier',
            'phone' => '01710000002',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ helpers */

    protected function insert(string $table, array $values): int
    {
        return (int) DB::table($table)->insertGetId($values + [
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, string> $keys */
    protected function userWith(array $keys): User
    {
        $user = $this->makeUser();
        $this->grant($user, $keys);

        return $user->fresh();
    }

    protected function typeId(string $code): int
    {
        return (int) DocumentType::query()->where('code', $code)->firstOrFail()->id;
    }

    protected function invoice(bool $taxed = false): Invoice
    {
        static $seq = 0;
        $seq++;

        $invoice = Invoice::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customerId,
            'document_type_id' => $this->typeId('invoice'),
            'invoice_no' => 'INV-DR-'.$seq,
            'status' => 'issued',
            'workflow_state' => 'issued',
            'posting_state' => 'posted',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(15)->toDateString(),
            'currency' => 'BDT',
            'subtotal' => 1000,
            'taxable_base' => 1000,
            'tax' => $taxed ? 150 : 0,
            'tax_applicable' => $taxed,
            'tax_code' => $taxed ? 'VAT15' : null,
            'grand_total' => $taxed ? 1150 : 1000,
            'paid_amount' => 400,
            'due_amount' => $taxed ? 750 : 600,
            'created_by' => $this->admin->id,
        ]);

        $this->insert('invoice_lines', [
            'company_id' => $this->admin->company_id,
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'description' => 'Rendered Product',
            'qty' => 10,
            'unit_price' => 100,
            'discount' => 0,
            'tax' => $taxed ? 150 : 0,
            'line_total' => $taxed ? 1150 : 1000,
        ]);

        return $invoice->fresh();
    }

    /** Two posted journal lines on one entry, which is all a voucher or a ledger reads. */
    protected function journalEntry(string $entryNo = 'JV-DR-1', float $amount = 1200): int
    {
        $accounts = Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('is_group', false)
            ->orderBy('id')
            ->limit(2)
            ->get();

        $period = DB::table('fiscal_periods')
            ->where('company_id', $this->admin->company_id)
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->orderBy('id')
            ->first();

        $entryId = $this->insert('journal_entries', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'fiscal_period_id' => $period?->id ?? $this->insert('fiscal_periods', [
                'company_id' => $this->admin->company_id,
                'code' => 'FY-DR-1',
                'name' => 'Period for the render test',
                'period_no' => 1,
                'starts_on' => now()->startOfYear()->toDateString(),
                'ends_on' => now()->endOfYear()->toDateString(),
                'status' => 'open',
            ]),
            'checksum' => hash('sha256', $entryNo),
            'entry_no' => $entryNo,
            'entry_date' => now()->toDateString(),
            'journal_type' => 'manual',
            'description' => 'Rendered journal',
            'total_debit' => $amount,
            'total_credit' => $amount,
            'posting_state' => 'posted',
            'posted_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        $this->insert('journal_lines', [
            'journal_entry_id' => $entryId,
            'company_id' => $this->admin->company_id,
            'account_id' => $accounts[0]->id,
            'dc' => 'debit',
            'amount' => $amount,
            'currency' => 'BDT',
            'narration' => 'Cash received',
            'line_no' => 1,
        ]);

        $this->insert('journal_lines', [
            'journal_entry_id' => $entryId,
            'company_id' => $this->admin->company_id,
            'account_id' => $accounts[1]->id,
            'dc' => 'credit',
            'amount' => $amount,
            'currency' => 'BDT',
            'narration' => 'Sales income',
            'line_no' => 2,
        ]);

        return $entryId;
    }

    /* ------------------------------------------------------------ the title rule */

    public function test_a_normal_sale_prints_as_invoice_and_a_statutory_form_keeps_its_own_title(): void
    {
        $titles = app(DocumentTitleService::class);

        $invoice = $titles->resolve('invoice', false);

        $this->assertSame('INVOICE', $invoice['title']);
        $this->assertFalse($invoice['statutory']);
        $this->assertFalse($invoice['tax_block'], 'A sale with no tax does not get a tax block.');

        $this->assertTrue($titles->resolve('invoice', true)['tax_block']);

        $mushak = $titles->resolve('mushak_9_1', true);

        $this->assertSame('MUSHAK 9.1', $mushak['title']);
        $this->assertTrue($mushak['statutory']);

        // A type that is not statutory may not borrow a statutory title: the
        // rule is enforced where the title is read, not trusted to the template.
        DocumentType::query()->where('code', 'quotation')->update(['printed_title' => 'TAX INVOICE']);

        $this->expectException(RuntimeException::class);

        app(DocumentTitleService::class)->resolve('quotation');
    }

    public function test_a_tax_column_is_not_printed_when_it_would_be_empty(): void
    {
        $plain = $this->invoice();

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $plain->id]))
            ->assertOk()
            ->assertDontSeeText('VAT15')
            ->assertDontSee('>VAT<', false);

        $taxed = $this->invoice(taxed: true);

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $taxed->id]))
            ->assertOk()
            ->assertSeeText('VAT15')
            ->assertSeeText('150.00');
    }

    /* --------------------------------------------------------------- the papers */

    public function test_every_supported_type_renders_through_the_same_shell(): void
    {
        $invoice = $this->invoice();

        $quotationId = $this->insert('quotations', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'customer_id' => $this->customerId,
            'quote_no' => 'QT-DR-1',
            'revision' => 0,
            'status' => 'sent',
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(20)->toDateString(),
            'currency' => 'BDT',
            'subtotal' => 500,
            'grand_total' => 500,
            'created_by' => $this->admin->id,
        ]);
        $this->insert('quotation_lines', [
            'company_id' => $this->admin->company_id,
            'quotation_id' => $quotationId,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'description' => 'Rendered Product',
            'qty' => 5,
            'unit_price' => 100,
            'line_total' => 500,
        ]);

        $orderId = $this->insert('sales_orders', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customerId,
            'order_no' => 'SO-DR-1',
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'currency' => 'BDT',
            'subtotal' => 500,
            'grand_total' => 500,
            'created_by' => $this->admin->id,
        ]);
        $this->insert('sales_order_lines', [
            'company_id' => $this->admin->company_id,
            'sales_order_id' => $orderId,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'description' => 'Rendered Product',
            'qty' => 5,
            'unit_price' => 100,
            'line_total' => 500,
        ]);

        $purchaseOrderId = $this->insert('purchase_orders', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplierId,
            'code' => 'PO-DR-1',
            'order_date' => now()->toDateString(),
            'status' => 'approved',
            'subtotal' => 800,
            'total' => 800,
            'created_by' => $this->admin->id,
        ]);
        $this->insert('purchase_order_lines', [
            'purchase_order_id' => $purchaseOrderId,
            'product_id' => $this->product->id,
            'description' => 'Rendered Product',
            'qty_ordered' => 8,
            'unit_price' => 100,
            'line_total' => 800,
        ]);

        $receiptId = $this->insert('goods_receipts', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplierId,
            'purchase_order_id' => $purchaseOrderId,
            'code' => 'GRN-DR-1',
            'challan_no' => 'SUP-CH-1',
            'received_date' => now()->toDateString(),
            'status' => 'posted',
            'subtotal' => 800,
            'total' => 800,
            'received_by' => $this->admin->id,
        ]);
        $this->insert('goods_receipt_lines', [
            'goods_receipt_id' => $receiptId,
            'product_id' => $this->product->id,
            'qty_received' => 8,
            'unit_cost' => 100,
            'line_total' => 800,
        ]);

        $challanId = $this->insert('delivery_challans', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $orderId,
            'document_type_id' => $this->typeId('delivery_challan'),
            'challan_no' => 'DC-DR-1',
            'status' => 'dispatched',
            'challan_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
        $this->insert('delivery_challan_lines', [
            'company_id' => $this->admin->company_id,
            'delivery_challan_id' => $challanId,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'description' => 'Rendered Product',
            'qty' => 5,
        ]);

        $paymentId = $this->insert('payments', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'customer_id' => $this->customerId,
            'receipt_no' => 'MR-DR-1',
            'direction' => 'in',
            'method' => 'cash',
            'amount' => 400,
            'status' => 'received',
            'paid_at' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        $creditNoteId = $this->insert('credit_notes', [
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->defaultBranch()->id,
            'customer_id' => $this->customerId,
            'document_type_id' => $this->typeId('credit_note'),
            'credit_note_no' => 'CN-DR-1',
            'status' => 'issued',
            'note_date' => now()->toDateString(),
            'subtotal' => 200,
            'grand_total' => 200,
            'reason' => 'Damaged on arrival',
            'created_by' => $this->admin->id,
        ]);
        $this->insert('credit_note_lines', [
            'company_id' => $this->admin->company_id,
            'credit_note_id' => $creditNoteId,
            'line_no' => 1,
            'product_id' => $this->product->id,
            'description' => 'Rendered Product',
            'qty' => 2,
            'unit_price' => 100,
            'line_total' => 200,
        ]);

        $entryId = $this->journalEntry();
        $accounts = Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('is_group', false)
            ->orderBy('id')
            ->limit(1)
            ->get();

        $papers = [
            ['invoice', $invoice->id, 'INVOICE', (string) $invoice->invoice_no, 'Rendered Customer'],
            ['quotation', $quotationId, 'QUOTATION', 'QT-DR-1', 'Rendered Customer'],
            ['sales_order', $orderId, 'SALES ORDER', 'SO-DR-1', 'Rendered Customer'],
            ['purchase_order', $purchaseOrderId, 'PURCHASE ORDER', 'PO-DR-1', 'Rendered Supplier'],
            ['goods_received_note', $receiptId, 'GOODS RECEIVED NOTE', 'GRN-DR-1', 'Rendered Supplier'],
            ['delivery_challan', $challanId, 'DELIVERY CHALLAN', 'DC-DR-1', 'Rendered Customer'],
            ['money_receipt', $paymentId, 'MONEY RECEIPT', 'MR-DR-1', 'Rendered Customer'],
            ['credit_note', $creditNoteId, 'CREDIT NOTE', 'CN-DR-1', 'Rendered Customer'],
            ['journal_voucher', $entryId, 'JOURNAL VOUCHER', 'JV-DR-1', 'POSTED'],
            ['account_ledger', $accounts[0]->id, 'ACCOUNT LEDGER', $accounts[0]->code, 'Opening balance'],
            ['party_statement', $this->customerId, 'STATEMENT OF ACCOUNT', 'DR-CUST', 'Rendered Customer'],
            ['audit_report', 0, 'AUDIT TRAIL REPORT', 'AUD-', 'Audit trail'],
        ];

        foreach ($papers as [$type, $id, $title, $reference, $party]) {
            $response = $this->actingAs($this->admin)
                ->get(route('documents.print.show', ['type' => $type, 'id' => $id]))
                ->assertOk();

            $response->assertSeeText($title);
            $response->assertSeeText($reference);
            $response->assertSeeText($party);
            $response->assertSee('table-header-group', false);
        }

        // Every one of them filed a copy and wrote a history row.
        $this->assertSame(count($papers), Document::query()->where('purpose', 'generated')->count());
        $this->assertSame(count($papers), PrintHistory::query()->count());

        $row = PrintHistory::query()->where('printed_title', 'DELIVERY CHALLAN')->firstOrFail();

        $this->assertSame('a4', $row->page_format);
        $this->assertSame('en', $row->locale);
        $this->assertNotNull($row->document_id);
        $this->assertSame('DELIVERY CHALLAN', $row->printed_title);
    }

    public function test_a_ledger_states_its_period_and_carries_a_running_balance(): void
    {
        $this->journalEntry('JV-DR-LEDGER', 1200);

        $account = Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('is_group', false)
            ->orderBy('id')
            ->firstOrFail();

        $response = $this->actingAs($this->admin)->get(route('documents.print.show', [
            'type' => 'account_ledger',
            'id' => $account->id,
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk();

        $response->assertSeeText('ACCOUNT LEDGER');
        $response->assertSeeText('01 Mar 2027 – 10 Mar 2027');
        $response->assertSeeText('Closing balance');
        $response->assertSeeText('Only posted journal entries are read');
    }

    /* ----------------------------------------------------- filing and history */

    public function test_a_printed_copy_is_filed_with_its_checksum_and_its_version_climbs(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertOk();

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertOk();

        $documents = Document::query()->where('purpose', 'generated')->orderBy('id')->get();

        $this->assertCount(2, $documents);
        $this->assertSame([1, 2], $documents->pluck('version')->map(fn ($v) => (int) $v)->all());

        foreach ($documents as $document) {
            $bytes = Storage::disk('local')->get($document->path);
            $this->assertSame(hash('sha256', $bytes), $document->checksum);
            $this->assertStringContainsString('INVOICE', $bytes);
        }

        $this->assertSame(2, PrintHistory::query()->where('document_id', '!=', null)->count());
        $this->assertSame(
            $documents->last()->checksum,
            PrintHistory::query()->orderByDesc('id')->first()->checksum,
        );
    }

    public function test_a_thermal_copy_and_a_watermarked_bengali_copy_are_recorded_as_such(): void
    {
        $invoice = $this->invoice();

        $response = $this->actingAs($this->admin)->get(route('documents.print.show', [
            'type' => 'invoice',
            'id' => $invoice->id,
            'page_format' => 'thermal',
            'locale' => 'bn',
            'watermark' => 'COPY',
            'copies' => 2,
        ]))->assertOk();

        $response->assertSee('80mm', false);
        $response->assertSeeText('COPY');
        $response->assertSeeText('মোট');

        $row = PrintHistory::query()->orderByDesc('id')->firstOrFail();

        $this->assertSame('thermal', $row->page_format);
        $this->assertSame('bn', $row->locale);
        $this->assertSame('COPY', $row->watermark);
        $this->assertSame(2, $row->copies);
    }

    public function test_saving_a_copy_is_a_download_and_is_audited_as_one(): void
    {
        $invoice = $this->invoice();

        $response = $this->actingAs($this->admin)->get(route('documents.print.show', [
            'type' => 'invoice',
            'id' => $invoice->id,
            'action' => 'download',
        ]))->assertOk();

        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString(
            'INVOICE-'.$invoice->invoice_no,
            (string) $response->headers->get('content-disposition'),
        );

        $this->assertSame(1, AuditEvent::query()->where('action', 'documents.downloaded')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'documents.printed')->count());

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertOk();

        $this->assertSame(1, AuditEvent::query()->where('action', 'documents.printed')->count());
    }

    public function test_printing_needs_the_document_types_own_permission(): void
    {
        $invoice = $this->invoice();

        $clerk = $this->userWith(['documents.view']);

        $this->actingAs($clerk)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertForbidden();

        $printer = $this->userWith(['sales.invoices.print']);

        $this->actingAs($printer)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertOk();

        // …and the same person may not print a ledger, because that is a
        // different document with a different key.
        $this->actingAs($printer)
            ->get(route('documents.print.show', ['type' => 'account_ledger', 'id' => 1]))
            ->assertForbidden();
    }

    public function test_the_history_reader_needs_its_own_key_and_shows_what_was_produced(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertOk();

        $reader = $this->userWith(['documents.view_history']);

        $this->actingAs($reader)
            ->get(route('documents.print.history', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertOk()
            ->assertSeeText('INVOICE')
            ->assertSeeText($this->admin->name);

        $stranger = $this->userWith(['documents.view']);

        $this->actingAs($stranger)
            ->get(route('documents.print.history', ['type' => 'invoice', 'id' => $invoice->id]))
            ->assertForbidden();

        $this->actingAs($reader)
            ->get(route('documents.print.log'))
            ->assertOk()
            ->assertSeeText('Print & download log')
            ->assertSeeText('Sales Invoice');
    }

    public function test_the_desk_lists_every_type_and_marks_the_ones_it_cannot_print(): void
    {
        $this->actingAs($this->admin)
            ->get(route('documents.print.index'))
            ->assertOk()
            ->assertSeeText('INVOICE')
            ->assertSeeText('STATEMENT OF ACCOUNT')
            ->assertSeeText('AUDIT TRAIL REPORT')
            // An engine that does not exist is named, not hidden.
            ->assertSeeText('No payroll engine exists yet')
            ->assertSeeText('documents.view_history');
    }

    public function test_every_printable_type_asks_for_a_permission_that_exists(): void
    {
        // A renderer type names the key that admits it. A key that was invented
        // rather than seeded 403s every non-administrator — and hides in local
        // testing, because the administrator role syncs whatever exists. This
        // walks the catalogue against the permissions table itself.
        $keys = DB::table('permissions')->pluck('key')->all();

        $asked = [];
        $typed = 0;

        foreach (app(\App\Domain\Documents\Services\DocumentRenderService::class)->catalogue() as $row) {
            $permission = $row['permission'] ?? null;

            if ($permission === null) {
                continue;
            }

            // Two types may share a key (a ledger and a statement are the same
            // read of the books), so count the types, not the keys.
            $typed++;
            $asked[$permission] = $row['code'];

            $this->assertContains(
                $permission,
                $keys,
                "The printable type [{$row['code']}] asks for [{$permission}], which no seeder defines.",
            );
        }

        $this->assertSame(12, $typed, 'Every printable type should name a permission.');

        // …and the four that were only ever assumed are now pinned.
        foreach ([
            'sales.quotations.view' => 'quotation',
            'sales.orders.print' => 'sales_order',
            'sales.delivery.print' => 'delivery_challan',
            'sales.payments.print' => 'money_receipt',
            'sales.invoices.print' => 'invoice',
            'audit.export' => 'audit_report',
        ] as $key => $code) {
            $this->assertSame($code, $asked[$key] ?? null);
        }
    }

    public function test_an_unknown_type_is_a_404_and_an_unbuilt_one_says_why(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/documents/print/nonsense/1')
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'payslip', 'id' => 1]))
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->get(route('documents.print.show', ['type' => 'invoice', 'id' => 99999]))
            ->assertNotFound();
    }
}
