<?php

namespace Tests\Feature;

use App\Domain\Documents\Services\DocumentTitleService;
use App\Domain\Foundation\Services\Translator;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-50 — the paper obeys the language the reader chose.
 *
 * A printed document's title is a claim about what the paper is (§16-24), and the
 * claim has to be readable in Bangla when the company prints in Bangla. The title
 * engine resolves the document type's code through the same `t()` the rest of the
 * interface uses, so "INVOICE" becomes "চালান" and the invoice print template that
 * prints it follows. Pinned:
 *
 *  · the title engine returns the Bangla title for the document types that print;
 *  · the actual invoice print template renders the Bangla title (not just the
 *    engine — the template must ask for it);
 *  · a statutory form with no Bangla row keeps its English title rather than
 *    inventing one.
 */
class DocumentsBanglaTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(\Database\Seeders\InventoryCoreSeeder::class);
        $this->seed(\Database\Seeders\SalesCoreSeeder::class);
        $this->seed(\Database\Seeders\DocumentTypeSeeder::class);
        $this->seed(\Database\Seeders\TranslationSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        app(\App\Domain\Foundation\Services\Translator::class)->forget();
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__doc-bn', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function invoice(): Invoice
    {
        $product = app(CreateProduct::class)->handle([
            'code' => 'BN-DOC-1',
            'sku' => 'BN-DOC-1-SKU',
            'name' => 'Bangla Document Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 100, 'unit_cost' => 80]],
            'idempotency_suffix' => 'bn-doc-open',
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();

        $customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'BN-C-1',
            'name' => 'Bangla Customer',
            'phone' => '01755550909',
            'address_line1' => 'House 1, Road 1',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 2, 'unit_price' => 150]],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return app(IssueInvoice::class)->handle(
            app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest()),
            $this->httpRequest(),
        );
    }

    public function test_the_title_engine_returns_bangla_titles(): void
    {
        app(Translator::class)->setLocale('bn');

        $this->assertSame('চালান', app(DocumentTitleService::class)->resolve('invoice')['title']);
        $this->assertSame('উদ্ধৃতি', app(DocumentTitleService::class)->titleFor('quotation'));
        $this->assertSame('ডেলিভারি চালান', app(DocumentTitleService::class)->titleFor('delivery_challan'));
    }

    public function test_the_invoice_print_template_renders_the_bangla_title(): void
    {
        $invoice = $this->invoice();

        app(Translator::class)->setLocale('bn');

        $html = view('sales.invoices.print', ['invoice' => $invoice->fresh()])->render();

        $this->assertStringContainsString('চালান', $html);
        $this->assertStringNotContainsString('>INVOICE<', $html);
    }

    public function test_an_untranslated_statutory_title_keeps_its_english_claim(): void
    {
        app(Translator::class)->setLocale('bn');

        // MUSHAK 9.1 has no Bangla row, so it keeps its English statutory title
        // rather than a mistranslation that would misstate what the paper is.
        $this->assertSame('MUSHAK 9.1', app(DocumentTitleService::class)->titleFor('mushak_9_1'));
    }
}
