<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\UpdateSalesOrder;
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
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-03 Order edit (draft/pending only): server-authoritative totals,
 * no stock/GL effect, invoice/reserved orders frozen, permission gates.
 */
class OrderEditDraftOnlyTest extends TestCase
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
            'code' => 'EDT-1',
            'sku' => 'EDT-SKU-1',
            'name' => 'Editable Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 100, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'edt-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__order-edit', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeOrder(array $overrides = []): SalesOrder
    {
        return app(CreateSalesOrder::class)->handle(array_merge([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 5, 'unit_price' => 150],
            ],
        ], $overrides), $this->httpRequest());
    }

    protected function editPayload(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 200, 'discount' => 0],
            ],
        ], $overrides);
    }

    public function test_edit_recomputes_totals_server_side_and_ignores_client_totals(): void
    {
        $order = $this->makeOrder();
        $this->assertEquals(750.0, (float) $order->grand_total);

        $journalsBefore = JournalEntry::query()->count();
        $movementsBefore = StockMovement::query()->count();
        $onHandBefore = (float) StockBalance::query()
            ->where('product_id', $this->product->id)
            ->value('on_hand');

        app(UpdateSalesOrder::class)->handle($order, array_merge(
            $this->editPayload(),
            ['grand_total' => 1, 'subtotal' => 99999], // client-sent totals must be ignored
        ), $this->httpRequest());

        $fresh = $order->fresh(['lines']);
        $this->assertCount(1, $fresh->lines);
        $this->assertEquals(10.0, (float) $fresh->lines->first()->qty);
        $this->assertEquals(200.0, (float) $fresh->lines->first()->unit_price);
        $this->assertEquals(2000.0, (float) $fresh->grand_total);
        $this->assertEquals(2000.0, (float) $fresh->subtotal);
        $this->assertSame('pending', $fresh->status);

        $this->assertSame($journalsBefore, JournalEntry::query()->count());
        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertEquals($onHandBefore, (float) StockBalance::query()
            ->where('product_id', $this->product->id)
            ->value('on_hand'));
    }

    public function test_edit_applies_document_discount_shipping_and_line_discount(): void
    {
        $order = $this->makeOrder();

        app(UpdateSalesOrder::class)->handle($order, $this->editPayload([
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 10, 'unit_price' => 200, 'discount' => 50],
            ],
            'doc_discount' => 100,
            'shipping' => 40,
        ]), $this->httpRequest());

        $fresh = $order->fresh();
        // 10×200 = 2000, line discount 50 → 1950, doc discount 100 → 1850, +40 shipping
        $this->assertEquals(1890.0, (float) $fresh->grand_total);
        $this->assertEquals(40.0, (float) $fresh->shipping);
        $this->assertEquals(150.0, (float) $fresh->discount); // line 50 + doc 100
    }

    public function test_confirmed_order_cannot_be_edited(): void
    {
        $order = $this->makeOrder();
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $this->actingAs($this->admin)
            ->put(route('sales.orders.update', $order), $this->editPayload())
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertSame('confirmed', $order->fresh()->status);
        $this->assertEquals(750.0, (float) $order->fresh()->grand_total);
        $this->assertEquals(5.0, (float) $order->fresh()->lines()->first()->qty);
    }

    public function test_order_with_an_invoice_cannot_be_edited(): void
    {
        $order = $this->makeOrder();
        app(ConfirmOrder::class)->handle($order, $this->httpRequest());
        app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());

        try {
            app(UpdateSalesOrder::class)
                ->handle($order->fresh(), $this->editPayload(), $this->httpRequest());
            $this->fail('Expected invoice guard RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has an invoice', $e->getMessage());
        }

        $this->assertEquals(750.0, (float) $order->fresh()->grand_total);
    }

    public function test_order_edit_routes_require_permission(): void
    {
        $order = $this->makeOrder();

        $denied = $this->makeUser();
        $denied->roles()->attach($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);

        $this->actingAs($denied)
            ->get(route('sales.orders.edit', $order))
            ->assertForbidden();

        $this->actingAs($denied)
            ->put(route('sales.orders.update', $order), $this->editPayload())
            ->assertForbidden();

        $this->assertEquals(750.0, (float) $order->fresh()->grand_total);

        $allowed = $this->makeUser();
        $allowed->roles()->sync($this->roleWith([
            'portal.erp.access',
            'sales.orders.view',
            'sales.orders.edit',
        ])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.edit', $order))
            ->assertOk()
            ->assertSee('lines-table');

        $this->actingAs($allowed)
            ->put(route('sales.orders.update', $order), $this->editPayload())
            ->assertRedirect(route('sales.orders.show', $order));

        $this->assertEquals(2000.0, (float) $order->fresh()->grand_total);
    }

    public function test_edit_requires_at_least_one_line(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->put(route('sales.orders.update', $order), $this->editPayload(['lines' => []]))
            ->assertRedirect()
            ->assertSessionHasErrors('lines');

        $this->assertEquals(750.0, (float) $order->fresh()->grand_total);
    }
}
