<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductBinAssignment;
use App\Domain\Inventory\Services\WarehouseService;
use App\Domain\Inventory\WarehouseBin;
use App\Domain\Inventory\WarehouseZone;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use RuntimeException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-42/04-43/04-45 — warehouses, their structure and the map.
 *
 * What this pins:
 *  · a warehouse belongs to a branch, and its code is unique inside that branch
 *    (it is what every stock row points at);
 *  · a warehouse that has moved stock is history — it can be renamed, but not
 *    switched off or deleted out from under the ledger;
 *  · zones carry a purpose, bins belong to a zone, and an empty zone is the only
 *    zone that can be removed; a bin with products still pointed at it cannot be;
 *  · exactly one primary pick face per product per warehouse — assigning a new
 *    one demotes the old one, and the answer is never ambiguous;
 *  · the map is the database: zones, bins and assignments rendered from rows, and
 *    a stale-placement list that a drawing could not produce.
 */
class WarehouseLayoutTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Branch $branch;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->branch = $this->defaultBranch();
        $this->bindTenantContext($this->admin, $this->branch);

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(InventoryCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();
    }

    protected function request(): Request
    {
        $request = Request::create('/__warehouses', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function service(): WarehouseService
    {
        return app(WarehouseService::class);
    }

    protected function stocked(string $sku, float $qty = 5): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Bin probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        if ($qty > 0) {
            app(CreateOpeningStock::class)->handle([
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => 10]],
                'idempotency_suffix' => uniqid('bin-', true),
            ], $this->request());
        }

        return $product;
    }

    public function test_a_warehouse_is_created_for_a_branch_and_its_code_is_unique_there(): void
    {
        $second = $this->service()->createWarehouse([
            'branch_id' => $this->branch->id,
            'code' => 'WH2',
            'name' => 'Second warehouse',
            'address' => 'Tejgaon',
        ], $this->admin);

        $this->assertSame('WH2', $second->code);
        $this->assertSame($this->branch->id, $second->branch_id);
        $this->assertTrue($second->is_active);

        // The code is what every stock row points at, so it cannot repeat in a branch.
        try {
            $this->service()->createWarehouse([
                'branch_id' => $this->branch->id,
                'code' => 'WH2',
                'name' => 'Duplicate',
            ], $this->admin);
            $this->fail('A duplicate warehouse code in the same branch must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has a warehouse coded WH2', $e->getMessage());
        }

        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.warehouse_created']);
    }

    public function test_a_warehouse_that_has_moved_stock_cannot_be_switched_off_or_deleted(): void
    {
        $product = $this->stocked('WH-STK');

        try {
            $this->service()->updateWarehouse($this->warehouse, ['is_active' => false], $this->admin);
            $this->fail('A warehouse holding stock must not be switched off.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be switched off', $e->getMessage());
        }

        try {
            $this->service()->deleteWarehouse($this->warehouse, $this->admin);
            $this->fail('A warehouse with stock history must not be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // Renaming is fine — the history is untouched.
        $renamed = $this->service()->updateWarehouse($this->warehouse, ['name' => 'Central store'], $this->admin);
        $this->assertSame('Central store', $renamed->name);
        $this->assertNotNull($product->id);

        // And a brand new, never-used warehouse can go.
        $spare = $this->service()->createWarehouse([
            'branch_id' => $this->branch->id,
            'code' => 'SPARE',
            'name' => 'Spare',
        ], $this->admin);

        $this->service()->deleteWarehouse($spare, $this->admin);
        $this->assertDatabaseMissing('warehouses', ['id' => $spare->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.warehouse_deleted']);
    }

    public function test_zones_and_bins_describe_the_place_and_refuse_to_leave_holes(): void
    {
        $zone = $this->service()->createZone($this->warehouse, [
            'code' => 'a',
            'name' => 'Rack area A',
            'type' => 'storage',
            'sort_order' => 10,
        ], $this->admin);

        $this->assertSame('A', $zone->code, 'codes are upper-cased so a label never disagrees with a document');

        $bin = $this->service()->createBin($zone, ['code' => 'a-01', 'name' => 'Rack A, shelf 1'], $this->admin);
        $this->assertSame('A-01', $bin->code);
        $this->assertTrue($bin->is_pickable);
        $this->assertSame($this->warehouse->id, $bin->warehouse_id, 'the bin knows its warehouse, not only its zone');
        $this->assertSame('A-01 · Rack A, shelf 1', $bin->label());

        try {
            $this->service()->createBin($zone, ['code' => 'A-01'], $this->admin);
            $this->fail('A duplicate bin code in a zone must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already exists in zone A', $e->getMessage());
        }

        // A zone with bins is not empty, so it does not disappear.
        try {
            $this->service()->deleteZone($zone, $this->admin);
            $this->fail('A zone with bins must not be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still has bins', $e->getMessage());
        }

        // A bin a picker is still being sent to is not removable either.
        $product = $this->stocked('WH-BIN');
        $this->service()->assignProduct($bin, $product->id, true, null, $this->admin);

        try {
            $this->service()->deleteBin($bin, $this->admin);
            $this->fail('A bin with assignments must not be deleted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still holds product assignments', $e->getMessage());
        }

        // Remove the assignment and the bin can go; then the zone can too.
        $this->service()->unassignProduct(ProductBinAssignment::query()->firstOrFail(), $this->admin);
        $this->service()->deleteBin($bin->refresh(), $this->admin);
        $this->service()->deleteZone($zone->refresh(), $this->admin);

        $this->assertSame(0, WarehouseBin::query()->count());
        $this->assertSame(0, WarehouseZone::query()->count());
    }

    public function test_a_product_has_one_primary_pick_face_per_warehouse(): void
    {
        $product = $this->stocked('WH-PICK');

        $zone = $this->service()->createZone($this->warehouse, ['code' => 'P', 'type' => 'picking'], $this->admin);
        $front = $this->service()->createBin($zone, ['code' => 'P-01'], $this->admin);
        $back = $this->service()->createBin($zone, ['code' => 'P-02'], $this->admin);

        $this->service()->assignProduct($front, $product->id, true, 'fast mover', $this->admin);
        $this->assertSame(1, ProductBinAssignment::query()->where('is_primary', true)->count());

        // Moving the pick face demotes the old one rather than leaving two answers.
        $this->service()->assignProduct($back, $product->id, true, null, $this->admin);

        $this->assertSame(1, ProductBinAssignment::query()->where('is_primary', true)->count());
        $this->assertTrue(ProductBinAssignment::query()->where('warehouse_bin_id', $back->id)->firstOrFail()->is_primary);
        $this->assertFalse(ProductBinAssignment::query()->where('warehouse_bin_id', $front->id)->firstOrFail()->is_primary);

        // And asking where to pick it answers with the primary face, per warehouse.
        $locations = $this->service()->pickLocations($product);
        $row = $locations->first(fn ($l) => $l['warehouse']->id === $this->warehouse->id);

        $this->assertNotNull($row);
        $this->assertSame($back->id, $row['bin']->id);
        $this->assertSame(5.0, $row['qty_on_hand'], 'the ledger still holds the quantity, not the bin');
    }

    public function test_the_map_is_rendered_from_rows_and_names_what_nobody_has_touched(): void
    {
        $product = $this->stocked('WH-MAP');

        $zone = $this->service()->createZone($this->warehouse, ['code' => 'S', 'name' => 'Storage', 'type' => 'storage'], $this->admin);
        $bin = $this->service()->createBin($zone, ['code' => 'S-01'], $this->admin);
        $this->service()->assignProduct($bin, $product->id, true, null, $this->admin);

        $layout = $this->service()->map($this->warehouse->refresh());

        $this->assertSame(1, $layout['totals']['zones']);
        $this->assertSame(1, $layout['totals']['bins']);
        $this->assertSame(1, $layout['totals']['pickable']);
        $this->assertSame(1, $layout['totals']['assignments']);
        $this->assertSame(1, $layout['totals']['products']);
        $this->assertSame('S', $layout['zones']->first()->code);
        $this->assertSame(1, $layout['zones']->first()->bins->count());

        // Nothing has moved for this product since the fixture ran seconds ago,
        // so a 60-day threshold finds nothing — the list is honest about that.
        $this->assertSame(0, $this->service()->staleAssignments($this->warehouse->refresh(), 60)->count());

        // With a threshold of zero days, the placement shows up as untouched today.
        $stale = $this->service()->staleAssignments($this->warehouse->refresh(), 0);
        $this->assertSame(1, $stale->count());
        $this->assertSame($product->id, $stale->first()['product']->id);
        $this->assertSame($bin->id, $stale->first()['bin']->id);
    }

    public function test_the_screens_are_gated_and_the_layout_can_be_managed_over_http(): void
    {
        $product = $this->stocked('WH-HTTP');

        $this->actingAs($this->admin)->get('/app/warehouses')->assertOk()->assertSee($this->warehouse->name);
        $this->actingAs($this->admin)->get(route('warehouses.show', $this->warehouse))->assertOk();

        $this->actingAs($this->admin)
            ->post(route('warehouses.zones.store', $this->warehouse), [
                'code' => 'R',
                'name' => 'Receiving',
                'type' => 'receiving',
            ])
            ->assertRedirect();

        $zone = WarehouseZone::query()->where('code', 'R')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('warehouses.bins.store', $zone), ['code' => 'R-01', 'is_pickable' => '1'])
            ->assertRedirect();

        $bin = WarehouseBin::query()->where('code', 'R-01')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('warehouses.bins.assign', $this->warehouse), [
                'bin_id' => $bin->id,
                'product_id' => $product->id,
                'is_primary' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('product_bin_assignments', [
            'product_id' => $product->id,
            'warehouse_bin_id' => $bin->id,
            'is_primary' => true,
        ]);

        // A bin from another warehouse cannot be reached through this warehouse's form.
        $other = $this->service()->createWarehouse([
            'branch_id' => $this->branch->id,
            'code' => 'OTHER',
            'name' => 'Other',
        ], $this->admin);
        $otherZone = $this->service()->createZone($other, ['code' => 'X'], $this->admin);
        $otherBin = $this->service()->createBin($otherZone, ['code' => 'X-01'], $this->admin);

        $this->actingAs($this->admin)
            ->from(route('warehouses.show', $this->warehouse))
            ->post(route('warehouses.bins.assign', $this->warehouse), [
                'bin_id' => $otherBin->id,
                'product_id' => $product->id,
            ])
            ->assertSessionHasErrors('bin_id');

        // Someone who may look at stock but not manage a warehouse.
        $viewer = $this->makeUser(['name' => 'Warehouse Viewer']);
        $viewer->roles()->attach($this->roleWith(['portal.erp.access', 'warehouses.view'])->id);

        $this->actingAs($viewer)->get('/app/warehouses')->assertOk();
        $this->actingAs($viewer)->get(route('warehouses.show', $this->warehouse))->assertOk();
        $this->actingAs($viewer)->get(route('warehouses.create'))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('warehouses.zones.store', $this->warehouse), ['code' => 'NOPE', 'type' => 'storage'])
            ->assertForbidden();
        $this->actingAs($viewer)->delete(route('warehouses.destroy', $other))->assertForbidden();
    }

    public function test_a_code_can_be_renamed_but_a_warehouse_that_holds_stock_keeps_its_branch(): void
    {
        $this->stocked('WH-RENAME');

        // A rename is safe: every stock row points at the id, not the code.
        $renamed = $this->service()->updateWarehouse($this->warehouse, [
            'code' => 'main-hub',
            'name' => 'Main hub',
        ], $this->admin);

        $this->assertSame('MAIN-HUB', $renamed->code, 'a code is upper-cased so no document is ambiguous');
        $this->assertSame('Main hub', $renamed->name);
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouse->id, 'code' => 'MAIN-HUB']);

        // The same code in the same branch is still taken, renamed or not.
        $second = $this->service()->createWarehouse([
            'branch_id' => $this->branch->id,
            'code' => 'SECOND',
            'name' => 'Second',
        ], $this->admin);

        try {
            $this->service()->updateWarehouse($second, ['code' => 'MAIN-HUB'], $this->admin);
            $this->fail('Renaming onto a code the branch already uses must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('already has a warehouse coded MAIN-HUB', $e->getMessage());
        }

        // Stock is reported branch by branch, so a warehouse that has moved stock
        // cannot quietly change which branch it reports under.
        $depot = Branch::create([
            'company_id' => $this->admin->company_id,
            'code' => 'CTG',
            'name' => 'Chittagong Depot',
            'is_active' => true,
        ]);

        try {
            $this->service()->updateWarehouse($this->warehouse, ['branch_id' => $depot->id], $this->admin);
            $this->fail('A warehouse holding stock must not move to another branch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be moved to another branch', $e->getMessage());
        }

        // A warehouse that has never moved stock is free to move — and it takes
        // its whole layout with it, because the rows point at the warehouse.
        $moved = $this->service()->updateWarehouse($second, ['branch_id' => $depot->id], $this->admin);
        $this->assertSame($depot->id, $moved->branch_id);
    }

    public function test_a_branch_has_one_default_warehouse_and_never_two(): void
    {
        $this->assertTrue($this->warehouse->is_default, 'the seeded MAIN warehouse is the branch default');

        $second = $this->service()->createWarehouse([
            'branch_id' => $this->branch->id,
            'code' => 'FRONT',
            'name' => 'Front counter',
            'is_default' => true,
        ], $this->admin);

        $this->assertTrue($second->is_default);
        $this->assertFalse($this->warehouse->refresh()->is_default, 'making one default demotes the other');
        $this->assertSame(
            1,
            Warehouse::query()->where('branch_id', $this->branch->id)->where('is_default', true)->count(),
            'a branch with two defaults would have no default',
        );

        // The same holds from the edit screen.
        $this->service()->updateWarehouse($this->warehouse, ['is_default' => true], $this->admin);

        $this->assertTrue($this->warehouse->refresh()->is_default);
        $this->assertFalse($second->refresh()->is_default);
        $this->assertSame(1, Warehouse::query()->where('branch_id', $this->branch->id)->where('is_default', true)->count());
    }
}
