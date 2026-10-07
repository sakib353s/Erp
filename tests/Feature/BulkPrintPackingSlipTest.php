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
use App\Domain\Sales\Actions\CreateSalesOrder;
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
 * 02-08 Bulk Print Packing Slip: BulkPrintAction(packing_slip) renders
 * a per-order HTML packing slip (PACKING SLIP title from the
 * document-type registry, items + qty, NEVER money), files it as a
 * generated document + print_history row, reports orders without lines
 * honestly, audits sales.order_bulk_print_packing_slip, and the whole
 * route/button is gated by the new sales.orders.print permission.
 */
class BulkPrintPackingSlipTest extends TestCase
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
            'code' => 'BPS-1',
            'sku' => 'BPS-SKU-1',
            'name' => 'Packing Slip Product',
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
            'idempotency_suffix' => 'bps-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-print-slip', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeConfirmedOrder(int $qty = 5): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    public function test_bulk_print_packing_slip_writes_documents_history_and_audit(): void
    {
        $first = $this->makeConfirmedOrder(3);
        $second = $this->makeConfirmedOrder(4);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-packing-slip'), [
                'order_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('2 printed', $status);
        $this->assertStringContainsString('0 failed', $status);

        $documents = Document::query()->where('purpose', 'generated')->orderBy('id')->get();
        $this->assertCount(2, $documents);

        foreach ($documents as $document) {
            $this->assertSame(SalesOrder::class, $document->owner_type);
            $this->assertSame('text/html', $document->mime_type);
            $this->assertSame($document->checksum, hash('sha256', Storage::disk('local')->get($document->path)));
        }

        $html = Storage::disk('local')->get($documents[0]->path);
        $this->assertStringContainsString('PACKING SLIP', $html);
        $this->assertStringContainsString($first->order_no, $html);

        $this->assertSame(2, PrintHistory::query()->where('format', 'html')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'sales.order_bulk_print_packing_slip')->count());
    }

    public function test_packing_slip_shows_items_and_qty_but_never_money(): void
    {
        $order = $this->makeConfirmedOrder(7);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-packing-slip'), ['order_ids' => [$order->id]])
            ->assertRedirect();

        $html = Storage::disk('local')->get(
            Document::query()->where('owner_id', $order->id)->firstOrFail()->path,
        );

        $this->assertStringContainsString('Packing Slip Product', $html);
        $this->assertStringContainsString('Qty to pack', $html);
        $this->assertStringContainsString('7', $html);

        $this->assertStringNotContainsString('Unit price', $html);
        $this->assertStringNotContainsString('Line total', $html);
        $this->assertStringNotContainsString('150.00', $html);
        $this->assertStringNotContainsString('Total', $html);
    }

    public function test_orders_without_lines_fail_with_that_message(): void
    {
        $order = $this->makeConfirmedOrder(2);
        $order->lines()->delete();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-packing-slip'), ['order_ids' => [$order->id]])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $message = $this->app['session']->get('errors')->first('order');
        $this->assertStringContainsString('no lines to pack', $message);
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, PrintHistory::query()->count());
    }

    public function test_route_and_button_respect_sales_orders_print(): void
    {
        $order = $this->makeConfirmedOrder(3);

        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.print-packing-slip'), ['order_ids' => [$order->id]])
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Print packing slip');

        $allowed = $this->makeUser(['name' => 'Packer']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.orders.print'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Print packing slip');

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.print-packing-slip'), ['order_ids' => [$order->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, Document::query()->count());
    }

    public function test_foreign_and_missing_orders_fail_without_touching_documents(): void
    {
        $order = $this->makeConfirmedOrder(3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-packing-slip'), [
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

    public function test_packing_slip_uses_its_document_type_and_records_who_printed(): void
    {
        $order = $this->makeConfirmedOrder(3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-packing-slip'), ['order_ids' => [$order->id]])
            ->assertRedirect();

        $type = DocumentType::query()->where('code', 'packing_slip')->firstOrFail();
        $this->assertSame('PACKING SLIP', $type->printed_title);
        $this->assertFalse((bool) $type->tax_applicable);

        $row = PrintHistory::query()->firstOrFail();
        $this->assertSame(SalesOrder::class, $row->printable_type);
        $this->assertSame($order->id, $row->printable_id);
        $this->assertSame($type->id, $row->document_type_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('html', $row->format);
        $this->assertNotNull($row->ip);

        $document = Document::query()->firstOrFail();
        $this->assertSame($type->id, $document->document_type_id);
    }
}
