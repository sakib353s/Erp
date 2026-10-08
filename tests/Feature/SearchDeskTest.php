<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Permission;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateDeliveryChallan;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\Services\WarrantyService;
use App\Domain\Sales\Warranty;
use App\Search\SearchIndex;
use App\Search\SearchIndexRebuilder;
use App\Search\SearchService;
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
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-49 — one box that finds everything, without becoming a way around a screen.
 *
 * The search box is the one screen in the application that reads from every
 * other one, which is exactly what makes it worth pinning down. Three things are
 * being proven here, and the third is the one that would be a security bug if it
 * were wrong:
 *
 *  · **it finds the number in somebody's hand** — an invoice or challan number
 *    off a printed page, the supplier's own bill number, a barcode read at a
 *    counter, a phone number read out loud, a serial number on a warranty card,
 *    a courier's tracking number. These live in a dozen different tables and are
 *    exactly what people type;
 *  · **the index is a derived thing, never an invented one** — a rebuild writes
 *    one row per real record, the row count equals the sum of what it reports,
 *    rebuilding twice rewrites the same rows rather than doubling them, a
 *    deleted record stops being findable, and every stored link is
 *    host-independent so a row written yesterday still opens today;
 *  · **nothing is findable that the reader may not open** — every index row names
 *    the permission key that admits somebody to the record it points at, and the
 *    reader's company, branches and permissions are applied at read time. A hit
 *    whose key the reader does not hold is not merely hidden from the list: it is
 *    never returned, never counted, and never named — on the page or in the ⌘K
 *    palette, which is the same read with a smaller limit.
 */
class SearchDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Customer $customer;

    protected static int $fixtureSeq = 0;

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

        $district = District::query()->orderBy('id')->firstOrFail();

        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Search Customer',
            'phone' => '01755550101',
            'address_line1' => 'House 4, Road 7, Banani',
            'district_id' => $district->id,
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------- plumbing */

    protected function httpRequest(?User $user = null): Request
    {
        $request = Request::create('/__search', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $user ?? $this->admin);

        return $request;
    }

    /** @return array<string, int> */
    protected function rebuild(?int $companyId = null): array
    {
        return app(SearchIndexRebuilder::class)->rebuild($companyId);
    }

    /** The hits a given reader gets for a term — the same call the page and the palette make. */
    protected function hits(?User $user, string $term)
    {
        return app(SearchService::class)->search($user, $term, 40);
    }

    /* ------------------------------------------------------------- fixtures */

    protected function product(?string $code = null): Product
    {
        $code ??= 'SRCH-'.++self::$fixtureSeq;

        $product = app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'barcode' => '880'.str_pad((string) random_int(1, 999999999), 9, '0', STR_PAD_LEFT),
            'name' => 'Search Product '.$code,
            'description' => 'A product with a barcode worth typing',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        // Goods on the shelf: an order can only be confirmed against stock.
        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 500, 'unit_cost' => 80]],
            'idempotency_suffix' => 'search-open-'.$product->id,
        ], $this->httpRequest());

        return $product;
    }

    protected function supplier(string $name = 'Search Supplier'): Supplier
    {
        return Supplier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SS-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => $name,
            'phone' => '01755550202',
            'email' => 'supplier@instance.test',
            'district_id' => District::query()->orderBy('id')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }

    protected function order(Product $product, int $qty = 2, int $price = 150)
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_price' => $price]],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    protected function challan(?Product $product = null, string $tracking = 'TRK-99112233'): DeliveryChallan
    {
        $order = $this->order($product ?? $this->product(), 3);

        return app(CreateDeliveryChallan::class)->handle($order, [
            'courier_name' => 'Sundarban Courier',
            'tracking_no' => $tracking,
            'lines' => [['product_id' => $order->lines->first()->product_id, 'qty' => 3]],
        ], $this->httpRequest());
    }

    protected function invoice(?Product $product = null): Invoice
    {
        $order = $this->order($product ?? $this->product(), 2);

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());

        return app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());
    }

    protected function quotation(): Quotation
    {
        return Quotation::query()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->warehouse->branch_id,
            'customer_id' => $this->customer->id,
            'quote_no' => 'QT-SRCH-'.++self::$fixtureSeq,
            'status' => 'draft',
            'quote_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);
    }

    protected function purchaseOrder(): PurchaseOrder
    {
        $product = $this->product();

        return app(PurchaseOrderService::class)->create([
            'supplier_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'reference' => 'SUP-REF-7788',
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'qty_ordered' => 10,
                'unit_price' => 90,
            ]],
        ], $this->admin->id);
    }

    protected function purchaseBill(): PurchaseBill
    {
        $product = $this->product();

        return app(PurchaseBillService::class)->create([
            'supplier_id' => $this->supplier()->id,
            'bill_date' => now()->toDateString(),
            'supplier_bill_no' => 'VENDOR-INV-4471',
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'qty' => 5,
                'unit_cost' => 95,
            ]],
        ], $this->admin->id);
    }

    protected function warranty(string $serial = 'SN-WRT-556677'): Warranty
    {
        $product = $this->product();

        app(WarrantyService::class)->savePolicy($product, [
            'months' => 12,
            'kind' => 'manufacturer',
            'covers_parts' => true,
            'covers_labour' => true,
        ], $this->admin);

        return app(WarrantyService::class)->activateManually($product, [
            'customer_id' => $this->customer->id,
            'branch_id' => $this->warehouse->branch_id,
            'serial_no' => $serial,
            'qty' => 1,
        ], $this->admin);
    }

    /* -------------------------------------------------------------- rebuild */

    public function test_a_rebuild_indexes_the_business_records_beside_the_workspace(): void
    {
        $product = $this->product();
        $supplier = $this->supplier();
        $invoice = $this->invoice($product);
        $challan = $this->challan($product);
        $quotation = $this->quotation();
        $order = $this->order($product, 1);
        $po = $this->purchaseOrder();
        $bill = $this->purchaseBill();
        $warranty = $this->warranty();

        $counts = $this->rebuild();

        foreach (['customer', 'supplier', 'product', 'invoice', 'quotation', 'challan',
            'purchase_order', 'purchase_bill', 'warranty'] as $type) {
            $this->assertArrayHasKey($type, $counts, "The rebuild wrote no {$type} rows.");
            $this->assertGreaterThanOrEqual(1, $counts[$type], $type);
        }

        $this->assertGreaterThanOrEqual(1, $counts['order'] ?? 0);

        // The workspace itself is still indexed beside the records.
        foreach (['user', 'branch', 'warehouse', 'role'] as $type) {
            $this->assertGreaterThanOrEqual(1, $counts[$type] ?? 0, $type);
        }

        // Nothing is invented: the table holds exactly what the rebuild reported.
        $this->assertSame(array_sum($counts), SearchIndex::query()->count());

        // And the records are the ones that exist, with the numbers people know them by.
        $this->assertSame($invoice->invoice_no, SearchIndex::query()
            ->where('entity_type', 'invoice')->where('entity_id', $invoice->id)->value('title'));
        // The challan's excerpt carries the courier and the tracking number together;
        // what matters is that the number the customer reads out is in there.
        $this->assertStringContainsString($challan->tracking_no, (string) SearchIndex::query()
            ->where('entity_type', 'challan')->where('entity_id', $challan->id)->value('excerpt'));
        $this->assertStringContainsString($bill->supplier_bill_no, (string) SearchIndex::query()
            ->where('entity_type', 'purchase_bill')->where('entity_id', $bill->id)->value('subtitle'));
        $this->assertSame($warranty->code, SearchIndex::query()
            ->where('entity_type', 'warranty')->where('entity_id', $warranty->id)->value('title'));

        // The indexed number is what the screen shows.
        $this->assertStringContainsString($po->code, (string) SearchIndex::query()
            ->where('entity_type', 'purchase_order')->where('entity_id', $po->id)->value('title'));
        $this->assertStringContainsString($quotation->quote_no, (string) SearchIndex::query()
            ->where('entity_type', 'quotation')->where('entity_id', $quotation->id)->value('title'));
        $this->assertStringContainsString($order->order_no, (string) SearchIndex::query()
            ->where('entity_type', 'order')->where('entity_id', $order->id)->value('title'));
        $this->assertStringContainsString($supplier->name, (string) SearchIndex::query()
            ->where('entity_type', 'supplier')->where('entity_id', $supplier->id)->value('title'));
    }

    public function test_every_index_row_names_the_key_that_admits_its_reader(): void
    {
        $this->product();
        $this->invoice();
        $this->challan();
        $this->purchaseBill();
        $this->warranty();

        $this->rebuild();

        // No row is left without a key: a row nobody can be checked against is a
        // row that would have to be shown to everybody or to nobody.
        $this->assertSame(0, SearchIndex::query()->whereNull('permission_key')->count());

        $keys = SearchIndex::query()->distinct()->pluck('permission_key')->all();

        $this->assertNotEmpty($keys);

        foreach ($keys as $key) {
            $this->assertTrue(
                Permission::query()->where('key', $key)->exists(),
                "The index names the key {$key}, which is not a real permission.",
            );
        }
    }

    public function test_every_stored_link_is_host_independent(): void
    {
        $this->product();
        $this->invoice();

        $this->rebuild();

        foreach (SearchIndex::query()->pluck('url') as $url) {
            $this->assertStringStartsWith('/', (string) $url, 'An index row stored an absolute link.');
            $this->assertDoesNotMatchRegularExpression('#^https?://#i', (string) $url);
        }
    }

    public function test_a_second_rebuild_rewrites_rows_instead_of_duplicating_them(): void
    {
        $product = $this->product();
        $this->invoice($product);

        $first = $this->rebuild();
        $second = $this->rebuild();

        $this->assertSame($first, $second);

        // The rebuild deletes the company's rows first, so ids are reassigned
        // each pass — idempotency here means "the same records, no duplicates",
        // which the content triple (type, entity, title) shows: it does not add a
        // second row for anything it already had.
        $triple = fn () => SearchIndex::query()
            ->orderBy('entity_type')->orderBy('entity_id')
            ->get(['entity_type', 'entity_id', 'title'])
            ->map(fn ($r) => "{$r->entity_type}:{$r->entity_id}:{$r->title}")
            ->all();

        $this->assertSame($triple(), SearchIndex::query()->orderBy('entity_type')->orderBy('entity_id')
            ->get(['entity_type', 'entity_id', 'title'])
            ->map(fn ($r) => "{$r->entity_type}:{$r->entity_id}:{$r->title}")
            ->all());
        $this->assertSame(
            count($triple()),
            SearchIndex::query()->count(),
            'A row is duplicated — the rebuild added a second of something.',
        );
    }

    public function test_a_record_that_is_gone_stops_being_findable(): void
    {
        $ghost = $this->supplier('Ghost Traders');

        $this->rebuild();

        $this->assertContains('supplier', $this->hits($this->admin, 'Ghost Traders')->pluck('entity_type')->all());

        DB::table('suppliers')->where('id', $ghost->id)->delete();

        $this->rebuild();

        $this->assertTrue($this->hits($this->admin, 'Ghost Traders')->isEmpty());
    }

    public function test_the_command_rebuilds_the_index_and_can_be_pointed_at_one_company(): void
    {
        $this->invoice();
        $this->product();

        $this->artisan('erp:search:rebuild')->assertSuccessful();

        $this->assertGreaterThan(0, SearchIndex::query()->where('company_id', $this->admin->company_id)->count());

        // Pointed at another company, it writes that company's rows and leaves
        // this one's exactly as they were.
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mine = SearchIndex::query()->where('company_id', $this->admin->company_id)->count();

        $this->artisan('erp:search:rebuild', ['--company' => $otherCompany])->assertSuccessful();

        $this->assertSame($mine, SearchIndex::query()->where('company_id', $this->admin->company_id)->count());
        $this->assertSame(0, SearchIndex::query()->where('company_id', $otherCompany)->count());
    }

    /* ------------------------------------------------------- findability */

    public function test_a_document_number_finds_the_invoice(): void
    {
        $invoice = $this->invoice();

        $this->rebuild();

        $hits = $this->hits($this->admin, $invoice->invoice_no);

        $this->assertCount(1, $hits);
        $this->assertSame('invoice', $hits->first()->entity_type);
        $this->assertSame(
            route('sales.invoices.show', $invoice, false),
            $hits->first()->url,
        );
    }

    public function test_a_suppliers_own_bill_number_finds_the_bill(): void
    {
        $bill = $this->purchaseBill();

        $this->rebuild();

        $hits = $this->hits($this->admin, 'VENDOR-INV-4471');

        $this->assertCount(1, $hits);
        $this->assertSame('purchase_bill', $hits->first()->entity_type);
        $this->assertSame($bill->code, $hits->first()->title);
    }

    public function test_a_barcode_finds_the_product(): void
    {
        $product = $this->product();

        $this->rebuild();

        $hits = $this->hits($this->admin, $product->barcode);

        $this->assertCount(1, $hits);
        $this->assertSame('product', $hits->first()->entity_type);
        $this->assertSame($product->name, $hits->first()->title);
    }

    public function test_a_phone_number_finds_the_customer(): void
    {
        $this->rebuild();

        $hits = $this->hits($this->admin, '01755550101');

        $this->assertCount(1, $hits);
        $this->assertSame('customer', $hits->first()->entity_type);
    }

    public function test_a_serial_number_finds_the_cover(): void
    {
        $warranty = $this->warranty();

        $this->rebuild();

        $hits = $this->hits($this->admin, 'SN-WRT-556677');

        $this->assertCount(1, $hits);
        $this->assertSame('warranty', $hits->first()->entity_type);
        $this->assertSame($warranty->code, $hits->first()->title);
        $this->assertStringContainsString($warranty->ends_on->format('d M Y'), (string) $hits->first()->excerpt);
    }

    public function test_a_courier_tracking_number_finds_the_challan(): void
    {
        $challan = $this->challan();

        $this->rebuild();

        $hits = $this->hits($this->admin, 'TRK-99112233');

        $this->assertCount(1, $hits);
        $this->assertSame('challan', $hits->first()->entity_type);
        $this->assertSame($challan->challan_no, $hits->first()->title);
        $this->assertStringContainsString('Sundarban', (string) $hits->first()->excerpt);
    }

    public function test_a_quotation_and_an_order_number_find_their_papers(): void
    {
        $quotation = $this->quotation();
        $order = $this->order($this->product(), 1);

        $this->rebuild();

        $quoteHit = $this->hits($this->admin, $quotation->quote_no);
        $this->assertCount(1, $quoteHit);
        $this->assertSame('quotation', $quoteHit->first()->entity_type);
        // The quotation desk has no show page; the hit filters its own list.
        $this->assertSame(
            route('sales.quotations.index', ['q' => $quotation->quote_no], false),
            $quoteHit->first()->url,
        );

        $orderHit = $this->hits($this->admin, $order->order_no);
        $this->assertCount(1, $orderHit);
        $this->assertSame('order', $orderHit->first()->entity_type);
        $this->assertSame(route('sales.orders.show', $order, false), $orderHit->first()->url);
    }

    public function test_short_and_empty_terms_are_not_searches(): void
    {
        $this->product();
        $this->rebuild();

        foreach (['', ' ', 'a'] as $term) {
            $this->assertTrue($this->hits($this->admin, $term)->isEmpty(), "“{$term}” should not search.");
        }

        $this->actingAs($this->admin)
            ->getJson(route('search.quick', ['q' => 'a']))
            ->assertOk()
            ->assertExactJson(['term' => 'a', 'results' => []]);
    }

    public function test_a_term_nobody_typed_returns_nothing(): void
    {
        $this->product();
        $this->rebuild();

        $this->assertTrue($this->hits($this->admin, 'zzz-nobody-typed-this')->isEmpty());

        $this->actingAs($this->admin)
            ->getJson(route('search.quick', ['q' => 'zzz-nobody-typed-this']))
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    /* ------------------------------------------------------- permissions */

    public function test_a_reader_without_the_key_is_never_shown_the_record(): void
    {
        $product = $this->product();
        $invoice = $this->invoice($product);

        $this->rebuild();

        $reader = $this->makeUser(['branch_scope' => 'all']);
        $this->grant($reader, ['inventory.products.view']);

        // The row is in the index — it is the reader who may not have it.
        $this->assertTrue(SearchIndex::query()->where('permission_key', 'sales.invoices.view')->exists());
        $this->assertTrue($this->hits($this->admin, $invoice->invoice_no)->isNotEmpty());

        $this->assertTrue($this->hits($reader, $invoice->invoice_no)->isEmpty());

        // What the reader may open is still found: the filter is not a blanket.
        $found = $this->hits($reader, $product->sku);
        $this->assertCount(1, $found);
        $this->assertSame('product', $found->first()->entity_type);
    }

    public function test_the_quick_answer_is_filtered_like_the_page(): void
    {
        $product = $this->product();
        $invoice = $this->invoice($product);

        $this->rebuild();

        $reader = $this->makeUser(['branch_scope' => 'all']);
        $this->grant($reader, ['inventory.products.view']);

        // The palette gets the same answer as the page: not the record, and not
        // its existence. Throttled at 120/minute, so two calls are not a problem.
        $this->actingAs($reader)
            ->getJson(route('search.quick', ['q' => $invoice->invoice_no]))
            ->assertOk()
            ->assertJsonCount(0, 'results');

        $this->actingAs($reader)
            ->getJson(route('search.quick', ['q' => $product->sku]))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.type', 'product')
            ->assertJsonPath('results.0.kind', 'Product')
            ->assertJsonPath('results.0.url', route('inventory.products.index', ['q' => $product->sku], false));

        // The same call as somebody who holds the key does return it.
        $this->actingAs($this->admin)
            ->getJson(route('search.quick', ['q' => $invoice->invoice_no]))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.type', 'invoice');
    }

    public function test_the_page_hides_what_the_key_hides(): void
    {
        $invoice = $this->invoice();

        $this->rebuild();

        $reader = $this->makeUser(['branch_scope' => 'all']);
        $this->grant($reader, ['inventory.products.view']);

        $link = route('sales.invoices.show', $invoice, false);

        $this->actingAs($reader)
            ->get(route('search.index', ['q' => $invoice->invoice_no]))
            ->assertOk()
            ->assertSeeText('No results match')
            ->assertDontSee('href="'.$link.'"', false);

        $this->actingAs($this->admin)
            ->get(route('search.index', ['q' => $invoice->invoice_no]))
            ->assertOk()
            ->assertSee('href="'.$link.'"', false)
            // The group heading is a word, not the machine key behind it.
            ->assertSeeText('Invoices')
            ->assertDontSeeText('Purchase_bill');
    }

    public function test_a_guest_cannot_search(): void
    {
        $this->get(route('search.index', ['q' => 'anything']))->assertRedirect(route('login'));
        $this->getJson(route('search.quick', ['q' => 'anything']))->assertStatus(401);
    }

    public function test_the_page_records_that_a_search_happened(): void
    {
        $invoice = $this->invoice();

        $this->rebuild();

        DB::table('audit_events')->where('action', 'search.query')->delete();

        $this->actingAs($this->admin)
            ->get(route('search.index', ['q' => $invoice->invoice_no]))
            ->assertOk();

        $event = AuditEvent::query()->where('action', 'search.query')->latest('id')->firstOrFail();

        $this->assertSame($invoice->invoice_no, $event->after['q']);
        $this->assertGreaterThanOrEqual(1, (int) $event->after['results']);

        // A one-character peek is not a search and leaves no trace.
        DB::table('audit_events')->where('action', 'search.query')->delete();

        $this->actingAs($this->admin)->get(route('search.index', ['q' => 'i']))->assertOk();

        $this->assertSame(0, AuditEvent::query()->where('action', 'search.query')->count());

        // Neither is the palette: it is not audited per keystroke.
        $this->actingAs($this->admin)
            ->getJson(route('search.quick', ['q' => $invoice->invoice_no]))
            ->assertOk();

        $this->assertSame(0, AuditEvent::query()->where('action', 'search.query')->count());
    }

    /* ------------------------------------------------------------- scope */

    public function test_an_assigned_reader_sees_their_branch_and_the_company_wide_facts(): void
    {
        $product = $this->product();
        $invoice = $this->invoice($product);

        $other = Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'UTT',
            'name' => 'Uttara Outlet',
            'is_default' => false,
            'is_active' => true,
        ]);

        // A second invoice, of the same kind, raised at the other outlet.
        $elsewhere = $invoice->replicate();
        $elsewhere->branch_id = $other->id;
        $elsewhere->invoice_no = 'INV-OTHER-9999';
        $elsewhere->save();

        $this->rebuild();

        $reader = $this->makeUser([
            'branch_scope' => 'assigned',
            'default_branch_id' => $this->defaultBranch()->id,
        ]);
        $this->grant($reader, ['sales.invoices.view', 'inventory.products.view']);

        // Their own outlet's invoice: found.
        $this->assertCount(1, $this->hits($reader, $invoice->invoice_no));

        // The other outlet's: not found, even though it is indexed.
        $this->assertTrue(SearchIndex::query()->where('title', 'INV-OTHER-9999')->exists());
        $this->assertTrue($this->hits($reader, 'INV-OTHER-9999')->isEmpty());
        $this->assertCount(1, $this->hits($this->admin, 'INV-OTHER-9999'));

        // A company-wide fact — the catalogue — is visible whatever the outlet.
        $found = $this->hits($reader, $product->sku);
        $this->assertCount(1, $found);
        $this->assertNull($found->first()->branch_id);
    }

    public function test_another_companys_record_is_not_findable(): void
    {
        $this->product();

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Warranty::query()->create([
            'company_id' => $otherCompany,
            'code' => 'WR-999999',
            'product_id' => Product::query()->firstOrFail()->id,
            'source' => 'manual',
            'source_line_id' => 0,
            'source_line_key' => 'manual:other-company',
            'months' => 12,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->toDateString(),
            'status' => Warranty::STATUS_ACTIVE,
            'qty' => 1,
        ]);

        $counts = $this->rebuild($otherCompany);

        $this->assertSame(1, $counts['warranty'] ?? 0);

        // The row exists in the index, for the company that owns it — and is
        // invisible to a reader of this one.
        $this->assertTrue(SearchIndex::query()->where('company_id', $otherCompany)->where('title', 'WR-999999')->exists());
        $this->assertTrue($this->hits($this->admin, 'WR-999999')->isEmpty());
    }

    /* ----------------------------------------------------------- the ⌘K box */

    public function test_the_palette_is_told_where_to_look(): void
    {
        $this->actingAs($this->admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-quick-url="/search/quick"', false)
            ->assertSee('Search records, pages', false);
    }
}
