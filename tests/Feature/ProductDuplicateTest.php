<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
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
 * §04-04 Duplicate product.
 *
 * The point of the feature is the configuration, not the inventory: copying a
 * product copies its category, brand, unit, cost method and tracking flags — and
 * copies no stock at all. A duplicate with the original's balances would invent
 * inventory out of a button press, which is the one thing a stock ledger must
 * never allow. The copy also gets no barcode (a physical label belongs to the
 * goods, and the copy has none until somebody labels them) and no cost history
 * (a cost nobody typed is not a decision).
 */
class ProductDuplicateTest extends TestCase
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
        $request = Request::create('/__duplicate', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function source(): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => 'DUP-1',
            'sku' => 'DUP-1-SKU',
            'name' => 'Duplicable widget',
            'description' => 'The source row.',
            'barcode' => '8801234567890',
            'cost_method' => 'fifo',
            'standard_cost' => 42,
            'is_stocked' => true,
            'track_batch' => true,
            'is_active' => true,
        ], $this->request());
    }

    protected function receive(Product $product, float $qty, float $cost): StockMovement
    {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => $qty,
            'unit_cost' => $cost,
            'batch_no' => 'DUP-LOT-1',
            'expires_on' => now()->addYear()->toDateString(),
            'idempotency_key' => uniqid('dup-recv-', true),
        ], $this->admin);
    }

    public function test_a_copy_takes_the_configuration_with_its_own_code_and_sku(): void
    {
        $source = $this->source();

        $this->actingAs($this->admin)
            ->post(route('inventory.products.duplicate', $source), [
                'code' => 'DUP-2',
                'sku' => 'DUP-2-SKU',
                'name' => 'Duplicable widget (large)',
            ])
            ->assertSessionHasNoErrors();

        $copy = Product::query()->where('code', 'DUP-2')->sole();

        $this->assertSame('Duplicable widget (large)', $copy->name);
        $this->assertSame('DUP-2-SKU', $copy->sku);
        $this->assertSame($source->product_category_id, $copy->product_category_id);
        $this->assertSame($source->brand_id, $copy->brand_id);
        $this->assertSame($source->unit_id, $copy->unit_id);
        $this->assertSame($source->cost_method, $copy->cost_method);
        $this->assertSame('42.0000', (string) $copy->standard_cost);
        $this->assertTrue((bool) $copy->track_batch);

        // A barcode is a label on physical goods — the copy has none of them yet.
        $this->assertNull($copy->barcode);
        $this->assertSame($this->admin->company_id, $copy->company_id);
    }

    public function test_a_copy_starts_with_no_stock_layers_or_history(): void
    {
        $source = $this->source();
        $this->receive($source, 7, 42);

        $this->assertSame(1, StockLayer::query()->where('product_id', $source->id)->count());

        $this->actingAs($this->admin)
            ->post(route('inventory.products.duplicate', $source), ['code' => 'DUP-3', 'sku' => 'DUP-3-SKU']);

        $copy = Product::query()->where('code', 'DUP-3')->sole();

        $this->assertSame(0, StockMovement::query()->where('product_id', $copy->id)->count());
        $this->assertSame(0, StockLayer::query()->where('product_id', $copy->id)->count());
        $this->assertSame(0, $copy->costHistory()->count());

        // A blank name is the only thing either of them inherits by default.
        $this->assertSame('Copy of '.$source->name, $copy->name);

        // The stock it did not copy is still exactly where it was.
        $this->assertSame(1, StockLayer::query()->where('product_id', $source->id)->count());
        $this->assertSame('7.0000', (string) StockLayer::query()->where('product_id', $source->id)->sole()->qty_remaining);
    }

    public function test_the_copy_needs_a_code_and_sku_that_are_still_free(): void
    {
        $source = $this->source();

        $this->actingAs($this->admin)
            ->post(route('inventory.products.duplicate', $source), [
                'code' => $source->code,
                'sku' => 'FREE-SKU-1',
            ])
            ->assertSessionHasErrors('code');

        $this->actingAs($this->admin)
            ->post(route('inventory.products.duplicate', $source), [
                'code' => 'DUP-FREE',
                'sku' => $source->sku,
            ])
            ->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::query()->where('company_id', $this->admin->company_id)->count());

        // The form suggests an identity that is actually free rather than
        // letting somebody submit the same code twice and read an error.
        $this->actingAs($this->admin)
            ->get(route('inventory.products.duplicate.form', $source))
            ->assertOk()
            ->assertSee('DUP-1-2')
            ->assertSee('DUP-1-SKU-2');
    }

    public function test_the_copy_is_audited_and_lands_on_the_new_products_form(): void
    {
        $source = $this->source();

        $this->actingAs($this->admin)
            ->post(route('inventory.products.duplicate', $source), ['code' => 'DUP-4', 'sku' => 'DUP-4-SKU'])
            ->assertRedirect(route('inventory.products.edit', Product::query()->where('code', 'DUP-4')->sole()));

        $copy = Product::query()->where('code', 'DUP-4')->sole();

        $audit = AuditEvent::query()
            ->where('action', 'inventory.product_duplicated')
            ->where('entity_id', $copy->id)
            ->first();

        $this->assertNotNull($audit, 'Duplicating a product must leave an audit trail.');
        $this->assertSame($source->id, (int) $audit->after['duplicated_from']);
        $this->assertFalse((bool) $audit->after['stock_copied']);
    }

    public function test_duplicating_another_companys_product_is_not_found(): void
    {
        $otherCompanyId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $strangerId = (int) DB::table('products')->insertGetId([
            'company_id' => $otherCompanyId,
            'code' => 'STRANGER-DUP',
            'sku' => 'STRANGER-DUP-SKU',
            'name' => 'Not ours to copy',
            'cost_method' => 'wac',
            'standard_cost' => 5,
            'is_stocked' => true,
            'track_batch' => false,
            'track_serial' => false,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.products.duplicate.form', $strangerId))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('inventory.products.duplicate', $strangerId), ['code' => 'X', 'sku' => 'X-SKU'])
            ->assertNotFound();

        $this->assertSame(0, Product::query()->where('code', 'X')->count());
    }

    public function test_the_product_list_offers_the_copy_action_only_to_whoever_may_create(): void
    {
        $this->source();

        $viewer = $this->makeUser(['name' => 'Catalogue Viewer']);
        $viewer->roles()->sync($this->roleWith(['portal.erp.access', 'inventory.products.view'])->id);
        app(PermissionCatalog::class)->invalidate($viewer);

        $this->actingAs($viewer)
            ->get(route('inventory.products.index'))
            ->assertOk()
            ->assertDontSee('>Copy</a>', false);

        $this->actingAs($viewer)
            ->get(route('inventory.products.duplicate.form', Product::query()->sole()))
            ->assertForbidden();

        $creator = $this->makeUser(['name' => 'Catalogue Creator']);
        $creator->roles()->sync($this->roleWith([
            'portal.erp.access', 'inventory.products.view', 'inventory.products.create',
        ])->id);
        app(PermissionCatalog::class)->invalidate($creator);

        $this->actingAs($creator)
            ->get(route('inventory.products.index'))
            ->assertOk()
            ->assertSee('>Copy</a>', false);

        $this->actingAs($creator)
            ->get(route('inventory.products.duplicate.form', Product::query()->sole()))
            ->assertOk();
    }
}
