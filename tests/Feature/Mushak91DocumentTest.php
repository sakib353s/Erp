<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentRenderer;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PrintHistory;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\TaxRate;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-50 Mushak 9.1 statutory tax invoice: a SEPARATE statutory
 * template (document_types.code=mushak_9_1, title MUSHAK 9.1 — the
 * commercial INVOICE print never changes), effective-dated rate
 * resolution through TaxService with an honest "not configured"
 * answer, a versioned filed document + print history + audit, an
 * honest refusal for non-tax-applicable invoices, and the new
 * sales.invoices.statutory_print gate.
 */
class Mushak91DocumentTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'MUSK-1',
            'sku' => 'MUSK-SKU-1',
            'name' => 'Mushak Product',
            'cost_method' => 'fifo',
            'standard_cost' => 60,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 200, 'unit_cost' => 50],
            ],
            'idempotency_suffix' => 'musk-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__mushak-91', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeVatRate(?string $effectiveFrom = null, ?string $effectiveTo = null): TaxRate
    {
        return TaxRate::create([
            'company_id' => $this->admin->company_id,
            'code' => 'VAT',
            'name' => 'VAT 15',
            'tax_type' => 'vat',
            'rate' => 15,
            'effective_from' => $effectiveFrom ?? now()->subDay(),
            'effective_to' => $effectiveTo,
            'is_active' => true,
        ]);
    }

    /** Tax invoice: qty 10 × 150 = 1,500 taxable + 15% VAT = 1,725. */
    protected function makeTaxInvoice(): Invoice
    {
        $this->makeVatRate();

        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'tax_applicable' => true,
            'tax_code' => 'VAT',
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [
            'tax_applicable' => true,
            'tax_code' => 'VAT',
        ], $this->httpRequest());

        return app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
    }

    protected function makePlainInvoice(): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());

        return app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
    }

    public function test_statutory_template_is_separate_and_the_filed_document_is_versioned(): void
    {
        $invoice = $this->makeTaxInvoice();

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertOk()
            ->assertSee('MUSHAK 9.1')
            ->assertDontSee('INVOICE');

        // The filed document is a real generated row with its own type.
        $document = Document::query()
            ->where('owner_type', Invoice::class)
            ->where('owner_id', $invoice->id)
            ->whereHas('documentType', fn ($q) => $q->where('code', 'mushak_9_1'))
            ->firstOrFail();

        $this->assertSame(1, (int) $document->version);
        $this->assertSame('generated', $document->purpose);
        $this->assertSame(64, strlen((string) $document->checksum));
        $this->assertGreaterThan(0, (int) $document->size_bytes);

        // Statutory type is distinct from the commercial invoice type.
        $invoiceType = DocumentType::query()->where('code', 'invoice')->firstOrFail();
        $mushakType = DocumentType::query()->where('code', 'mushak_9_1')->firstOrFail();
        $this->assertSame('INVOICE', $invoiceType->printed_title);
        $this->assertSame('MUSHAK 9.1', $mushakType->printed_title);
        $this->assertTrue((bool) $mushakType->is_statutory);
        $this->assertNotSame($invoiceType->id, $mushakType->id);

        // Printing the commercial invoice separately still produces a
        // different document — the two templates never converge.
        $commercial = app(DocumentRenderer::class)->renderInvoice($invoice, $this->admin);
        $this->assertSame('invoice', $commercial->documentType?->code);
        $this->assertStringContainsString(
            'INVOICE',
            Storage::disk('local')->get($commercial->path),
        );
        $this->assertNotSame($commercial->id, $document->id);

        // Print history + audit carry the statutory print.
        $history = PrintHistory::query()->where('format', 'html')->get();
        $this->assertSame(1, $history->count());
        $this->assertSame(
            'mushak_9_1',
            DocumentType::query()->where('id', $history->first()->document_type_id)->value('code'),
        );
        $this->assertSame($this->admin->id, $history->first()->user_id);

        $audit = AuditEvent::query()
            ->where('action', 'sales.invoice_mushak_printed')
            ->where('entity_id', $invoice->id)
            ->firstOrFail();
        $this->assertSame('invoice', $audit->entity_type);
        $this->assertSame($invoice->invoice_no, $audit->after['invoice_no'] ?? null);
        $this->assertSame(1, (int) ($audit->after['document_version'] ?? 0));
    }

    public function test_effective_dated_rate_resolves_for_the_invoice_date(): void
    {
        $invoice = $this->makeTaxInvoice();

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertOk()
            ->assertSee('Effective tax rate for code')
            ->assertSee('VAT 15')
            ->assertSee('— 15%')
            ->assertSee('1,500.00')
            ->assertSee('225.00')
            ->assertSee('1,725.00');
    }

    public function test_rate_not_yet_effective_is_answered_honestly(): void
    {
        $invoice = $this->makeTaxInvoice();

        // The only configured rate starts next month — it is not
        // effective for this invoice's date, so the document says so
        // instead of inventing a percentage.
        TaxRate::query()->update(['effective_from' => now()->addMonth()]);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertOk()
            ->assertSee('No effective tax rate is configured for code')
            ->assertDontSee('— 15%');

        // The recorded VAT amounts remain the invoice's own truth.
        $this->actingAs($this->admin)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertSee('225.00');
    }

    public function test_non_tax_applicable_invoice_is_refused_with_no_side_effects(): void
    {
        $invoice = $this->makePlainInvoice();
        $this->assertFalse((bool) $invoice->tax_applicable);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertStatus(422);

        $this->assertSame(0, Document::query()->where('owner_id', $invoice->id)->count());
        $this->assertSame(0, PrintHistory::query()->count());
        $this->assertSame(
            0,
            AuditEvent::query()->where('action', 'sales.invoice_mushak_printed')->count(),
        );
    }

    public function test_route_requires_statutory_print_and_scopes_to_the_company(): void
    {
        $invoice = $this->makeTaxInvoice();

        $viewer = $this->makeUser();
        $viewer->roles()->attach($this->roleWith([
            'portal.erp.access', 'sales.invoices.view',
        ])->id);

        $this->actingAs($viewer)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertForbidden();

        $printer = $this->makeUser();
        $printer->roles()->attach($this->roleWith([
            'portal.erp.access', 'sales.invoices.statutory_print',
        ])->id);

        $this->actingAs($printer)
            ->get(route('sales.invoices.mushak-91', $invoice))
            ->assertOk();

        $shadowCompanyId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Mushak Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowInvoiceId = DB::table('invoices')->insertGetId([
            'company_id' => $shadowCompanyId,
            'document_type_id' => DocumentType::query()->where('code', 'invoice')->value('id'),
            'invoice_no' => 'SHADOW-M-1',
            'status' => 'issued',
            'invoice_date' => now()->toDateString(),
            'currency' => 'BDT',
            'grand_total' => 100,
            'tax_applicable' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.mushak-91', $shadowInvoiceId))
            ->assertNotFound();
    }

    public function test_invoice_show_offers_the_button_only_when_permitted_and_applicable(): void
    {
        $taxInvoice = $this->makeTaxInvoice();
        $plainInvoice = $this->makePlainInvoice();

        // Assert the per-invoice button URL — the sidebar menu leaf
        // always carries the words "Mushak 9.1" for admins.
        $taxUrl = route('sales.invoices.mushak-91', $taxInvoice);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.show', $taxInvoice))
            ->assertOk()
            ->assertSee($taxUrl);

        $this->actingAs($this->admin)
            ->get(route('sales.invoices.show', $plainInvoice))
            ->assertOk()
            ->assertDontSee('invoices/'.$plainInvoice->id.'/mushak-9.1');

        $viewer = $this->makeUser();
        $viewer->roles()->attach($this->roleWith([
            'portal.erp.access', 'sales.invoices.view',
        ])->id);

        $this->actingAs($viewer)
            ->get(route('sales.invoices.show', $taxInvoice))
            ->assertOk()
            ->assertDontSee('invoices/'.$taxInvoice->id.'/mushak-9.1');
    }

    public function test_menu_leaf_is_gated_by_the_statutory_print_permission(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tax Invoice (Mushak 9.1)');

        $limited = $this->makeUser();
        $limited->roles()->attach($this->roleWith([
            'portal.erp.access', 'dashboard.view', 'sales.invoices.view',
        ])->id);

        $this->actingAs($limited)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Tax Invoice (Mushak 9.1)');
    }
}
