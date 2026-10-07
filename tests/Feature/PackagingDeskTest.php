<?php

namespace Tests\Feature;

use App\Domain\Delivery\Actions\ConsumePackaging;
use App\Domain\Delivery\PackagingType;
use App\Domain\Delivery\PackagingUsage;
use App\Domain\Delivery\Services\PackagingService;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
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
 * §04-59/04-60/04-61/04-62 — packaging from the inventory side.
 *
 * What this pins:
 *  · a type points at a real stock-managed product, and the register says when
 *    that stopped being true instead of letting dispatch find out;
 *  · the code and the product behind a type are frozen once it has consumed
 *    anything — a box that was costed under one code cannot become another;
 *  · a type that has taken stock out cannot be deleted, only retired, because
 *    the movements that priced it would be left pointing at nothing;
 *  · packaging stock is the same ledger as everything else (a consumption shows
 *    up as a smaller shelf), and the cost of what went out is the layer cost at
 *    the moment it went out, averaged — never a price typed on the type;
 *  · the report groups by month and type, so "more units" and "dearer units"
 *    can be told apart.
 */
class PackagingDeskTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $box;

    protected Product $sold;

    protected Customer $customer;

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

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->box = app(CreateProduct::class)->handle([
            'code' => 'PKD-BOX',
            'sku' => 'PKD-BOX',
            'name' => 'Corrugated box, 12 inch',
            'cost_method' => 'fifo',
            'standard_cost' => 12,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        $this->sold = app(CreateProduct::class)->handle([
            'code' => 'PKD-SOLD',
            'sku' => 'PKD-SOLD',
            'name' => 'Packed product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        $this->stock($this->box, 50, 12.50);
        $this->stock($this->sold, 200, 80);

        $district = District::query()->orderBy('id')->firstOrFail();

        $this->customer = Customer::create([
            'company_id' => $this->admin->company_id,
            'code' => 'PKD-C1',
            'name' => 'Packaging desk customer',
            'phone' => '01733333340',
            'address_line1' => 'House 9, Road 9, Uttara',
            'district_id' => $district->id,
            'is_active' => true,
        ]);
    }

    protected function request(): Request
    {
        $request = Request::create('/__packaging-desk', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function stock(Product $product, float $qty, float $unitCost): void
    {
        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => $unitCost]],
            'idempotency_suffix' => uniqid('pkd-', true),
        ], $this->request());
    }

    protected function type(string $code = 'BOX12', ?Product $product = null): PackagingType
    {
        return app(PackagingService::class)->createType([
            'code' => $code,
            'name' => 'Corrugated box — '.$code,
            'product_id' => ($product ?? $this->box)->id,
        ], $this->admin);
    }

    protected function pack(PackagingType $type, float $qty = 4): PackagingUsage
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->sold->id, 'qty' => 5, 'unit_price' => 150]],
        ], $this->request());

        app(ConfirmOrder::class)->handle($order, $this->request());

        return app(ConsumePackaging::class)->handle([
            'sales_order_id' => $order->id,
            'packaging_type_id' => $type->id,
            'qty' => $qty,
        ], $this->request());
    }

    /* ------------------------------------------------------------------ tests --- */

    public function test_a_type_is_declared_against_a_stock_managed_product_of_this_company(): void
    {
        $this->actingAs($this->admin)
            ->post(route('inventory.packaging.types.store'), [
                'code' => 'box12',
                'name' => 'Corrugated box, 12 inch',
                'product_id' => $this->box->id,
            ])
            ->assertRedirect(route('inventory.packaging.index'))
            ->assertSessionHasNoErrors();

        $type = PackagingType::query()->firstOrFail();

        $this->assertSame('BOX12', $type->code, 'the code is stored as the floor writes it');
        $this->assertSame((int) $this->box->id, (int) $type->product_id);
        $this->assertTrue($type->is_active);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.packaging_type_created']);

        // A service is not packaging: the ledger could never take it out of stock.
        $service = app(CreateProduct::class)->handle([
            'code' => 'PKD-SVC',
            'sku' => 'PKD-SVC',
            'name' => 'Delivery charge',
            'cost_method' => 'wac',
            'standard_cost' => 0,
            'is_stocked' => false,
            'is_active' => true,
        ], $this->request());

        $this->actingAs($this->admin)
            ->post(route('inventory.packaging.types.store'), [
                'code' => 'BAGS',
                'name' => 'Carrier bags',
                'product_id' => $service->id,
            ])
            ->assertSessionHasErrors('packaging');

        // And a duplicate code is refused by validation, with the key named.
        $this->actingAs($this->admin)
            ->post(route('inventory.packaging.types.store'), [
                'code' => 'BOX12',
                'name' => 'Another box',
                'product_id' => $this->box->id,
            ])
            ->assertSessionHasErrors('code');
    }

    public function test_the_register_says_when_a_type_can_no_longer_be_used(): void
    {
        $type = $this->type();

        $look = app(PackagingService::class)->types();
        $this->assertTrue($look['rows']->first()['usable']);

        // The product behind it stops being stocked: the type is now a promise
        // the ledger refuses to keep, and the desk says so before dispatch does.
        $this->box->forceFill(['is_stocked' => false])->save();

        $look = app(PackagingService::class)->types();
        $row = $look['rows']->first();

        $this->assertFalse($row['usable']);

        $this->actingAs($this->admin)
            ->get(route('inventory.packaging.index'))
            ->assertOk()
            ->assertSee('Cannot be used');
    }

    public function test_code_and_product_are_frozen_once_the_type_has_consumed_anything(): void
    {
        $type = $this->type();
        $other = app(CreateProduct::class)->handle([
            'code' => 'PKD-BAG',
            'sku' => 'PKD-BAG',
            'name' => 'Carrier bag',
            'cost_method' => 'fifo',
            'standard_cost' => 3,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        $this->stock($other, 100, 3);

        // While it is untouched, a mistake can be corrected.
        $this->actingAs($this->admin)
            ->put(route('inventory.packaging.types.update', $type), [
                'code' => 'BOX-12',
                'name' => 'Box, 12 inch',
                'product_id' => $type->product_id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('BOX-12', $type->fresh()->code);

        $this->pack($type->fresh());
        $afterPacking = $type->fresh();

        // Once stock has been taken out under it, the name may still be
        // corrected — but not the identity that the consumption priced.
        $this->actingAs($this->admin)
            ->put(route('inventory.packaging.types.update', $afterPacking), [
                'code' => 'BOX-13',
                'name' => 'Box, 12 inch',
                'product_id' => $afterPacking->product_id,
            ])
            ->assertSessionHasErrors('packaging');

        $this->actingAs($this->admin)
            ->put(route('inventory.packaging.types.update', $afterPacking), [
                'code' => 'BOX-12',
                'name' => 'Box, 12 inch — renamed',
                'product_id' => $other->id,
            ])
            ->assertSessionHasErrors('packaging');

        $this->actingAs($this->admin)
            ->put(route('inventory.packaging.types.update', $afterPacking), [
                'code' => 'BOX-12',
                'name' => 'Box, 12 inch — renamed',
                'product_id' => $afterPacking->product_id,
            ])
            ->assertSessionHasNoErrors();

        $renamed = $type->fresh();
        $this->assertSame('Box, 12 inch — renamed', $renamed->name);
        $this->assertSame((int) $this->box->id, (int) $renamed->product_id, 'the consumption still points at the same product');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.packaging_type_updated']);
    }

    public function test_a_used_type_is_retired_never_deleted_while_an_unused_one_can_go(): void
    {
        $used = $this->type('USED');
        $unused = $this->type('UNUSED');

        $this->pack($used, 2);

        $this->actingAs($this->admin)
            ->delete(route('inventory.packaging.types.destroy', $used))
            ->assertSessionHasErrors('packaging');

        $this->assertNotNull($used->fresh(), 'the movements that priced it must still find their type');

        $this->actingAs($this->admin)
            ->delete(route('inventory.packaging.types.destroy', $unused))
            ->assertSessionHasNoErrors();

        $this->assertNull($unused->fresh());
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.packaging_type_deleted']);

        // Retiring is the honest alternative: the type stays on the record, and
        // consumption refuses it from then on.
        $this->actingAs($this->admin)
            ->post(route('inventory.packaging.types.toggle', $used))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $used->fresh()->is_active);

        $this->expectException(\RuntimeException::class);
        $this->pack($used->fresh(), 1);
    }

    public function test_a_retired_type_cannot_come_back_without_a_usable_product(): void
    {
        $type = $this->type();

        $this->actingAs($this->admin)->post(route('inventory.packaging.types.toggle', $type));

        $this->assertFalse((bool) $type->fresh()->is_active);

        // Coming back is a promise to the ledger, so it has to be checkable.
        $this->box->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin)
            ->post(route('inventory.packaging.types.toggle', $type))
            ->assertSessionHasErrors('packaging');

        $this->assertFalse((bool) $type->fresh()->is_active, 'a type the ledger would refuse stays retired');
    }

    public function test_packaging_stock_is_the_same_ledger_and_the_costs_come_from_the_layers(): void
    {
        $type = $this->type();

        $this->pack($type, 4);

        $balance = StockBalance::query()
            ->where('product_id', $this->box->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertSame(46.0, (float) $balance->on_hand, 'fifty boxes, four shipped');

        $this->actingAs($this->admin)
            ->get(route('inventory.packaging.stock'))
            ->assertOk()
            ->assertSee('46.0000')
            ->assertSee('BOX12');

        $cost = app(PackagingService::class)->cost(null, 90);
        $row = $cost['rows']->first(fn ($candidate) => $candidate['type']->code === 'BOX12');

        $this->assertNotNull($row);
        $this->assertSame(4.0, $row['consumed_qty']);
        $this->assertSame(50.0, $row['consumed_cost'], 'four boxes at the layer cost of 12.50');
        $this->assertSame(12.5, $row['consumed_unit_cost']);
        $this->assertSame(1, $row['consumed_orders']);
        $this->assertSame(575.0, $row['stock_value'], 'the remaining 46 boxes are still worth their layer cost');

        $this->actingAs($this->admin)
            ->get(route('inventory.packaging.cost'))
            ->assertOk()
            ->assertSee('12.5000');
    }

    public function test_the_report_tells_more_units_apart_from_dearer_units(): void
    {
        // Its own product and type, so the layers are exactly known: four boxes
        // at 12.50, all of them shipped, then a dearer delivery at 20.00. FIFO
        // would otherwise take the cheap layer first and hide the price move
        // this test exists to prove.
        $boxes = app(CreateProduct::class)->handle([
            'code' => 'PKD-2TONE',
            'sku' => 'PKD-2TONE',
            'name' => 'Two-tone box',
            'cost_method' => 'fifo',
            'standard_cost' => 12,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        $this->stock($boxes, 4, 12.5);

        $type = $this->type('2TONE', $boxes);
        $this->pack($type, 4);

        $this->stock($boxes, 20, 20);
        $this->pack($type, 6);

        $this->actingAs($this->admin)
            ->get(route('inventory.reports.packaging', ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee('2TONE')
            ->assertSee('Corrugated box — 2TONE');

        $report = app(PackagingService::class)->report(
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
        );

        $this->assertSame(10.0, $report['totals']['qty']);
        $this->assertSame(2, $report['totals']['orders']);
        $this->assertSame(1, $report['totals']['months']);

        $row = $report['rows']->first();

        $this->assertSame(now()->format('Y-m'), $row['period']);
        $this->assertSame(10.0, $row['qty']);
        $this->assertSame(2, $row['orders']);
        // 4 boxes at 12.50 = 50, 6 at 20.00 = 120 → 170 over ten boxes.
        $this->assertSame(170.0, $row['cost']);
        $this->assertSame(17.0, $row['unit_cost']);

        // The CSV is the same rows the screen shows — filters included — so it
        // can be read by a machine without re-deriving the period.
        $csv = $this->actingAs($this->admin)
            ->get(route('inventory.reports.packaging', [
                'from' => now()->startOfMonth()->toDateString(),
                'to' => now()->toDateString(),
                'format' => 'csv',
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Month,Code,Packaging,Quantity,Cost', $csv);
        $this->assertStringContainsString('2TONE', $csv);
        $this->assertStringContainsString('170', $csv);
    }

    public function test_a_period_with_nothing_consumed_is_an_empty_report_not_a_guess(): void
    {
        $this->type();

        $report = app(PackagingService::class)->report(
            now()->subMonths(6)->startOfMonth()->toDateString(),
            now()->subMonths(6)->endOfMonth()->toDateString(),
        );

        $this->assertCount(0, $report['rows']);
        $this->assertSame(0.0, $report['totals']['cost']);
        $this->assertSame(0, $report['totals']['orders']);

        $this->actingAs($this->admin)
            ->get(route('inventory.reports.packaging', [
                'from' => now()->subMonths(6)->startOfMonth()->toDateString(),
                'to' => now()->subMonths(6)->endOfMonth()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('No packaging was consumed in this period');
    }

    public function test_another_companys_type_is_not_found(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Packers Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $type = PackagingType::create([
            'company_id' => $otherCompany,
            'code' => 'THEIRS',
            'name' => 'Their box',
            'product_id' => $this->box->id,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('inventory.packaging.types.update', $type), [
                'code' => 'THEIRS',
                'name' => 'Renamed',
                'product_id' => $this->box->id,
            ])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('inventory.packaging.types.toggle', $type))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('inventory.packaging.types.destroy', $type))
            ->assertNotFound();
    }
}
