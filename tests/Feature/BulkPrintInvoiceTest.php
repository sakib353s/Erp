<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PrintHistory;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\SalesOrder;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-07 Bulk Print Invoice: BulkPrintAction(invoice) renders a real
 * HTML document per issued invoice (checksum/size from the bytes),
 * logs print_history rows (user + IP + format html — never claims pdf
 * without an engine), reports orders without invoices honestly, writes
 * the bulk audit row, and is gated by sales.invoices.print. Printed
 * title is INVOICE from the document-type registry and tax only shows
 * when there is tax.
 */
class BulkPrintInvoiceTest extends TestCase
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
            'code' => 'BPI-1',
            'sku' => 'BPI-SKU-1',
            'name' => 'Bulk Print Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 200, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'bpi-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-print', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** @return array{0: SalesOrder, 1: Invoice} */
    protected function makeConfirmedOrderWithInvoice(int $qty = 5): array
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle(
            $order->fresh(),
            [],
            $this->httpRequest(),
        );

        return [$order->fresh(), app(IssueInvoice::class)->handle($invoice, $this->httpRequest())];
    }

    public function test_bulk_print_renders_documents_print_history_and_audit(): void
    {
        [$firstOrder, $firstInvoice] = $this->makeConfirmedOrderWithInvoice(3);
        [$secondOrder, $secondInvoice] = $this->makeConfirmedOrderWithInvoice(4);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-invoice'), [
                'order_ids' => [$firstOrder->id, $secondOrder->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('2 printed', $status);
        $this->assertStringContainsString('0 failed', $status);

        $documents = Document::query()->where('purpose', 'generated')->orderBy('id')->get();
        $this->assertCount(2, $documents);

        foreach ($documents as $document) {
            $this->assertSame('text/html', $document->mime_type);
            $this->assertGreaterThan(0, $document->size_bytes);

            $bytes = Storage::disk('local')->get($document->path);
            $this->assertSame($document->checksum, hash('sha256', $bytes));
            $this->assertSame($document->size_bytes, strlen($bytes));
            $this->assertStringContainsString('INVOICE', $bytes);
        }

        $html = Storage::disk('local')->get($documents[0]->path);
        $this->assertStringContainsString($firstInvoice->invoice_no, $html);

        $this->assertSame(
            2,
            PrintHistory::query()->where('format', 'html')->count(),
        );
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.order_bulk_print_invoice')->count(),
        );
    }

    public function test_printed_invoice_shows_tax_only_when_there_is_tax(): void
    {
        [$taxedOrder, $taxed] = $this->makeConfirmedOrderWithInvoice(3);
        [$plainOrder, $plain] = $this->makeConfirmedOrderWithInvoice(3);

        $taxed->forceFill(['tax' => 15])->save();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-invoice'), [
                'order_ids' => [$taxedOrder->id, $plainOrder->id],
            ])
            ->assertRedirect();

        $taxedHtml = Storage::disk('local')->get(
            Document::query()->where('owner_id', $taxed->id)->firstOrFail()->path,
        );
        $this->assertStringContainsString('Tax', $taxedHtml);

        $plainHtml = Storage::disk('local')->get(
            Document::query()->where('owner_id', $plain->id)->firstOrFail()->path,
        );
        $this->assertStringNotContainsString('Tax', $plainHtml);
        $this->assertStringContainsString('INVOICE', $plainHtml);
    }

    public function test_orders_without_an_issued_invoice_fail_with_that_message(): void
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 2, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-invoice'), [
                'order_ids' => [$order->fresh()->id],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $message = $this->app['session']->get('errors')->first('order');
        $this->assertStringContainsString('No issued invoice', $message);
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, PrintHistory::query()->count());
    }

    public function test_print_route_and_button_respect_sales_invoices_print(): void
    {
        [$order] = $this->makeConfirmedOrderWithInvoice(3);

        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.print-invoice'), ['order_ids' => [$order->id]])
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Print invoice');

        $allowed = $this->makeUser(['name' => 'Print Clerk']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.invoices.print'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Print invoice');

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.print-invoice'), ['order_ids' => [$order->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, Document::query()->count());
    }

    public function test_missing_orders_fail_without_touching_documents(): void
    {
        [$order] = $this->makeConfirmedOrderWithInvoice(3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-invoice'), [
                'order_ids' => [$order->id, 99999999],
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 printed', $status);
        $this->assertStringContainsString('1 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('Order not found', $messages);

        $this->assertSame(1, Document::query()->count());
        $this->assertSame(1, PrintHistory::query()->count());
    }

    public function test_print_history_records_who_printed_what(): void
    {
        [$order, $invoice] = $this->makeConfirmedOrderWithInvoice(3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-invoice'), ['order_ids' => [$order->id]])
            ->assertRedirect();

        $row = PrintHistory::query()->firstOrFail();
        $this->assertSame(Invoice::class, $row->printable_type);
        $this->assertSame($invoice->id, $row->printable_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('html', $row->format);
        $this->assertSame(1, $row->copies);
        $this->assertNotNull($row->ip);
        $this->assertNotNull($row->correlation_id);

        $invoiceType = DocumentType::query()->where('code', 'invoice')->firstOrFail();
        $this->assertSame($invoiceType->id, $row->document_type_id);
        $this->assertSame('INVOICE', $invoiceType->printed_title);
    }
}
