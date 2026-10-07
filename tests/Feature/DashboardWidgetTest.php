<?php

namespace Tests\Feature;

use App\Domain\Dashboard\Services\DashboardMetrics;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\ReorderService;
use App\Domain\Purchase\Services\GoodsReceiptService;
use App\Domain\Purchase\Services\PurchaseBillService;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Masters\Supplier;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\PurchaseCoreSeeder;
use Database\Seeders\WidgetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §01 — the dashboard's 25 containers.
 *
 * What this pins:
 *  · every container resolves to one of three honest states and never invents a
 *    number: a figure from real documents, an empty state, or a plain statement
 *    that its module has no source yet;
 *  · a document that is not posted does not become a figure;
 *  · the containers read the same sources the modules do (reorder policies,
 *    open bills), so the dashboard cannot disagree with the screen it links to;
 *  · one container can be fetched on its own, and a container the caller may not
 *    see is refused.
 */
class DashboardWidgetTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(PurchaseCoreSeeder::class);
        $this->seed(WidgetSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();
    }

    protected function request(): Request
    {
        $request = Request::create('/__dashboard', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function metrics(): array
    {
        return app(DashboardMetrics::class)->for();
    }

    protected function makeProduct(string $sku, float $qty): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Dashboard probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => 100]],
            'idempotency_suffix' => uniqid('dash-', true),
        ], $this->request());

        return $product;
    }

    public function test_every_container_resolves_to_an_honest_state_on_an_empty_instance(): void
    {
        $metrics = $this->metrics();

        $this->assertCount(25, $metrics, 'decision D22: exactly 25 containers');

        foreach ($metrics as $code => $metric) {
            $this->assertContains($metric['state'], ['ok', 'empty', 'unavailable'], $code);
            $this->assertArrayHasKey('caption', $metric, $code);
        }

        // Nothing has happened yet, so nothing may look like a figure.
        $this->assertSame('empty', $metrics['todays_sales']['state']);
        $this->assertNull($metrics['todays_sales']['primary']);
        $this->assertSame('empty', $metrics['pending_approvals']['state']);
        $this->assertSame('empty', $metrics['top_10_products']['state']);
        $this->assertSame('empty', $metrics['payable_aging']['state']);

        // Expiry has no source at all yet, and the panel says why.
        $this->assertSame('unavailable', $metrics['expiring_products_alert']['state']);
        $this->assertStringContainsString('expiry', (string) $metrics['expiring_products_alert']['note']);
    }

    public function test_a_document_becomes_a_figure_only_after_it_is_posted(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->admin->company_id,
            'code' => 'SUP-DASH',
            'name' => 'Dashboard Supplies',
            'is_active' => true,
        ]);

        $product = $this->makeProduct('DASH-P', 0);

        $orders = app(PurchaseOrderService::class);
        $order = $orders->create([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Dashboard probe',
                'qty_ordered' => 5,
                'unit_price' => 100,
                'discount' => 0,
                'tax_rate' => 0,
            ]],
        ], $this->admin->id);

        $orders->approve($order, $this->admin->id);

        $receipts = app(GoodsReceiptService::class);
        $receipt = $receipts->create([
            'purchase_order_id' => $order->id,
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'received_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->first()->id,
                'product_id' => $product->id,
                'qty_received' => 5,
                'unit_cost' => 100,
            ]],
        ], $this->admin->id);

        // A draft receipt has changed nothing yet: no stock, no bill, no figure.
        $this->assertSame('empty', $this->metrics()['todays_purchase']['state']);

        $receipts->post($receipt, $this->admin->id);

        $bills = app(PurchaseBillService::class);
        $bill = $bills->create([
            'supplier_id' => $supplier->id,
            'goods_receipt_id' => $receipt->id,
            'bill_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => 'Dashboard probe',
                'qty' => 5,
                'unit_cost' => 100,
            ]],
        ], $this->admin->id);

        // Nobody has approved it, so nothing is owed yet — and the panel knows.
        $this->assertSame('empty', $this->metrics()['todays_purchase']['state']);

        $bills->approve($bill, $this->makeUser()->id);

        $metrics = $this->metrics();

        $this->assertSame('ok', $metrics['todays_purchase']['state']);
        $this->assertSame('500.00', $metrics['todays_purchase']['primary']);
        $this->assertStringContainsString('1 bill', (string) $metrics['todays_purchase']['caption']);

        $this->assertSame('ok', $metrics['payable_aging']['state']);
        $this->assertSame('500.00', $metrics['payable_aging']['primary']);
        $this->assertNotEmpty($metrics['payable_aging']['rows'], 'the buckets are shown, not just the total');
    }

    public function test_the_stock_panels_read_the_same_policies_the_alert_screen_does(): void
    {
        $watched = $this->makeProduct('DASH-LOW', 5);
        $this->makeProduct('DASH-FINE', 500);

        // Only the watched product has a policy: an alert needs a stated threshold.
        app(ReorderService::class)->savePolicy($watched, [
            'min_level' => 10,
            'max_level' => 100,
            'reorder_point' => 20,
            'safety_stock' => 2,
            'reorder_qty' => 40,
            'lead_time_days' => 7,
        ], $this->warehouse->id, $this->admin->id);

        $metrics = $this->metrics();

        $this->assertSame('ok', $metrics['low_stock_alert']['state']);
        $this->assertSame('1', $metrics['low_stock_alert']['primary']);
        $this->assertSame('empty', $metrics['out_of_stock_alert']['state'], 'nothing is out of stock');
    }

    public function test_one_container_can_be_fetched_alone_and_others_are_refused(): void
    {
        $this->actingAs($this->admin)
            ->get('/app/dashboard/widgets/low_stock_alert')
            ->assertOk()
            ->assertJsonPath('code', 'low_stock_alert')
            ->assertJsonPath('state', 'empty');

        $this->actingAs($this->admin)
            ->get('/app/dashboard/widgets/not_a_container')
            ->assertNotFound();

        // Someone who may open the dashboard but may not read stock: the panel
        // is not among their containers, and the endpoint refuses it even when
        // the address is typed by hand.
        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access', 'dashboard.view'])->id);

        $this->actingAs($outsider)->get('/app/dashboard/widgets/low_stock_alert')->assertForbidden();
        $this->actingAs($outsider)->get('/app/dashboard')
            ->assertOk()
            ->assertDontSee('Low stock');

        // The page itself renders the same payloads inline for a permitted user.
        $this->actingAs($this->admin)->get('/app/dashboard')
            ->assertOk()
            ->assertSee("Today's sales")
            ->assertSee('Low stock');
    }
}
