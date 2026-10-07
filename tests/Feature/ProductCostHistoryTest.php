<?php

namespace Tests\Feature;

use App\Domain\Foundation\MenuItem;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductCostHistory;
use App\Domain\Inventory\Services\ProductService;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\StockLayer;
use App\Domain\Inventory\StockMovement;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-10 Product cost history (and the per-product view of §04-14).
 *
 * What this pins:
 *  · the trail is written when — and only when — an edit actually moves the
 *    standard cost or the cost method; a rename is not a cost decision;
 *  · the reason and the author travel with the row, because "who raised it and
 *    why" is the whole point of keeping the trail;
 *  · a cost-method change is recorded and still never rewrites a posted
 *    valuation layer: the layer keeps the cost the goods arrived at;
 *  · both screens (the register and the per-product trail) read the same rows
 *    and are gated by inventory.products.view — writing the cost stays behind
 *    inventory.products.edit, where the product form already lives.
 */
class ProductCostHistoryTest extends TestCase
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
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->orderBy('id')
            ->firstOrFail();
    }

    protected function request(): Request
    {
        $request = Request::create('/__cost-history', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function product(string $sku, float $cost = 100, string $method = 'fifo'): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Cost probe '.$sku,
            'cost_method' => $method,
            'standard_cost' => $cost,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());
    }

    /** @param array<string, mixed> $data */
    protected function change(Product $product, array $data, ?User $actor = null): Product
    {
        return app(ProductService::class)->update($product, $data, $actor ?? $this->admin);
    }

    protected function receive(Product $product, float $qty, float $cost): StockMovement
    {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => $qty,
            'unit_cost' => $cost,
            'idempotency_key' => uniqid('cost-recv-', true),
        ], $this->admin);
    }

    public function test_a_cost_edit_is_recorded_with_from_to_reason_and_author(): void
    {
        $product = $this->product('COST-1', 100);

        $this->change($product, [
            'standard_cost' => 140,
            'cost_change_reason' => 'Supplier raised the rate from 1 September.',
        ]);

        $row = ProductCostHistory::query()->where('product_id', $product->id)->sole();

        $this->assertSame('100.0000', (string) $row->old_standard_cost);
        $this->assertSame('140.0000', (string) $row->new_standard_cost);
        $this->assertSame('Supplier raised the rate from 1 September.', $row->reason);
        $this->assertSame($this->admin->id, $row->changed_by);
        $this->assertNotNull($row->changed_at);
        $this->assertStringContainsString('standard cost', $row->summary());

        // The row is history, not a cache: the product itself carries the figure.
        $this->assertSame('140.0000', (string) $product->refresh()->standard_cost);
    }

    public function test_an_edit_that_moves_nothing_writes_no_history_row(): void
    {
        $product = $this->product('COST-2', 100);

        $this->change($product, ['name' => 'Renamed only', 'description' => 'Still the same cost.']);

        $this->assertSame(0, ProductCostHistory::query()->where('product_id', $product->id)->count());
        $this->assertSame('Renamed only', $product->refresh()->name);

        // Re-saving the same cost and the same method is not a change either.
        $this->change($product, ['standard_cost' => 100, 'cost_method' => 'fifo']);

        $this->assertSame(0, ProductCostHistory::query()->where('product_id', $product->id)->count());
    }

    public function test_a_cost_method_change_is_recorded_without_rewriting_posted_layers(): void
    {
        $product = $this->product('COST-3', 100, 'fifo');
        $this->receive($product, 5, 25);

        $layer = StockLayer::query()->where('product_id', $product->id)->sole();

        $this->change($product, [
            'cost_method' => 'wac',
            'cost_change_reason' => 'Moving to weighted average for this category.',
        ]);

        $row = ProductCostHistory::query()->where('product_id', $product->id)->sole();

        $this->assertSame('fifo', $row->old_cost_method);
        $this->assertSame('wac', $row->new_cost_method);
        $this->assertStringContainsString('cost method', $row->summary());

        // The posted layer is a fact about goods that arrived; a dropdown on the
        // product record does not get to rewrite it.
        $this->assertSame('25.0000', $layer->refresh()->unit_cost);
        $this->assertSame('wac', $product->refresh()->cost_method);
    }

    public function test_the_register_lists_products_with_the_date_their_cost_last_moved(): void
    {
        $changed = $this->product('COST-4A', 100);
        $quiet = $this->product('COST-4B', 55);

        $this->change($changed, ['standard_cost' => 130, 'cost_change_reason' => 'Freight went up.']);

        $this->actingAs($this->admin)
            ->get(route('inventory.cost-history'))
            ->assertOk()
            ->assertSee($changed->sku)
            ->assertSee($quiet->sku)
            ->assertSee('Cost changes recorded')
            ->assertSee(route('inventory.products.cost-history', $changed), false);

        // …and the filter can single out the products nothing ever moved.
        $this->actingAs($this->admin)
            ->get(route('inventory.cost-history', ['changed' => 'never']))
            ->assertOk()
            ->assertSee($quiet->sku)
            ->assertDontSee($changed->sku);
    }

    public function test_the_per_product_screen_shows_the_trail_and_the_valuation_layers(): void
    {
        $product = $this->product('COST-5', 100);
        $this->receive($product, 4, 30);
        $this->change($product, ['standard_cost' => 150, 'cost_change_reason' => 'New season pricing.']);

        $this->actingAs($this->admin)
            ->get(route('inventory.products.cost-history', $product))
            ->assertOk()
            ->assertSee($product->sku)
            ->assertSee('Cost changes')
            ->assertSee('New season pricing.')
            ->assertSee('What the stock cost')
            ->assertSee('120.00');       // 4 × 30, read from the valuation layer
    }

    public function test_the_per_product_screen_is_a_404_for_another_companys_product(): void
    {
        $otherCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stranger = (int) DB::table('products')->insertGetId([
            'company_id' => $otherCompanyId,
            'code' => 'STRANGER',
            'sku' => 'STRANGER-SKU',
            'name' => 'Not ours',
            'cost_method' => 'wac',
            'standard_cost' => 10,
            'is_stocked' => true,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.products.cost-history', $stranger))
            ->assertNotFound();
    }

    public function test_cost_history_reads_need_the_products_view_permission(): void
    {
        $product = $this->product('COST-6', 100);
        $this->change($product, ['standard_cost' => 175, 'cost_change_reason' => 'Repriced.']);

        $denied = $this->makeUser(['name' => 'No Inventory Access']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('inventory.cost-history'))
            ->assertForbidden();

        $viewer = $this->makeUser(['name' => 'Cost Viewer']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'inventory.products.view'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)
            ->get(route('inventory.cost-history'))
            ->assertOk()
            ->assertSee($product->sku);

        $this->actingAs($viewer)
            ->get(route('inventory.products.cost-history', $product))
            ->assertOk()
            ->assertSee('Repriced.');

        // Reading the trail is not writing the cost: the product form keeps its
        // own gate, so a viewer cannot move the figure they are reading.
        $this->actingAs($viewer)
            ->put(route('inventory.products.update', $product), [
                'code' => $product->code,
                'sku' => $product->sku,
                'name' => $product->name,
                'cost_method' => 'wac',
                'standard_cost' => 999,
            ])
            ->assertForbidden();

        $this->assertSame('175.0000', (string) $product->refresh()->standard_cost);
    }

    public function test_the_cost_history_menu_leaf_points_at_the_register(): void
    {
        $leaf = MenuItem::query()
            ->join('permissions', 'permissions.id', '=', 'menu_items.permission_id')
            ->where('menu_items.label', 'Product Cost History')
            ->select('menu_items.*', 'permissions.key as permission_key')
            ->firstOrFail();

        $this->assertSame('active', $leaf->status);
        $this->assertTrue($leaf->is_active);
        $this->assertSame('/app/inventory/cost-history', $leaf->route);
        $this->assertSame('inventory.products.view', $leaf->permission_key);
    }
}
