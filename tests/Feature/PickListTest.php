<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\PickList;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductBinAssignment;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Inventory\Services\WarehouseService;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\WarehouseBin;
use App\Domain\Inventory\WarehouseZone;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\SalesOrderLine;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-44 — pick lists.
 *
 * What this pins:
 *  · a list written for an order takes each line's *undelivered* quantity, so a
 *    half-delivered order is not picked twice;
 *  · every line names the bin to walk to (the product's pick face) and, for
 *    batch-tracked goods under FEFO, the batch the shelf should give up first;
 *  · a box left empty means "not finished", never "picked nothing", and nobody
 *    can pick more than the sheet asked for;
 *  · one open walk per order — a second list is the same job written twice;
 *  · finishing the walk moves the order to ready-to-ship and **touches no
 *    stock**: the ledger changes at dispatch, where it belongs.
 */
class PickListTest extends TestCase
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

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();
    }

    protected function request(): Request
    {
        $request = Request::create('/__picks', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function product(string $sku, bool $trackBatch = false): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Pick probe '.$sku,
            'cost_method' => 'fifo',
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
            'track_batch' => $trackBatch,
        ], $this->request());
    }

    /** A bin in a zone with the given purpose — picking faces are what pickers walk to. */
    protected function bin(string $code, string $zoneCode = 'PICK', string $purpose = 'picking'): WarehouseBin
    {
        $zones = app(WarehouseService::class);

        $zone = WarehouseZone::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('code', $zoneCode)
            ->first()
            ?? $zones->createZone($this->warehouse, ['code' => $zoneCode, 'name' => $zoneCode, 'type' => $purpose], $this->admin);

        return $zones->createBin($zone, ['code' => $code, 'name' => $code], $this->admin);
    }

    protected function assign(Product $product, WarehouseBin $bin, bool $primary = true): ProductBinAssignment
    {
        return app(WarehouseService::class)->assignProduct($bin, $product->id, $primary, null, $this->admin);
    }

    /** Confirmed order with the given [product, ordered, delivered] lines. */
    protected function order(array $lines, string $status = 'confirmed'): SalesOrder
    {
        $order = SalesOrder::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->default_branch_id,
            'warehouse_id' => $this->warehouse->id,
            'order_no' => 'SO-'.uniqid(),
            'status' => $status,
            'order_date' => now()->toDateString(),
            'created_by' => $this->admin->id,
        ]);

        $lineNo = 0;

        foreach ($lines as [$product, $qty, $delivered]) {
            $lineNo++;

            SalesOrderLine::create([
                'company_id' => $this->admin->company_id,
                'sales_order_id' => $order->id,
                'line_no' => $lineNo,
                'product_id' => $product->id,
                'description' => $product->name,
                'qty' => $qty,
                'delivered_qty' => $delivered,
                'unit_price' => 100,
                'line_total' => $qty * 100,
            ]);
        }

        return $order->load('lines');
    }

    protected function receive(Product $product, float $qty, ?string $batchNo = null, ?string $expiresOn = null): StockMovement
    {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => $qty,
            'unit_cost' => 10,
            'batch_no' => $batchNo,
            'expires_on' => $expiresOn,
            'idempotency_key' => uniqid('pick-recv-', true),
        ], $this->admin);
    }

    protected function list(): PickList
    {
        return PickList::query()->latest('id')->firstOrFail();
    }

    /** What the ledger says is on the shelf, read from the balance it maintains. */
    protected function onHand(Product $product): float
    {
        return (float) (StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand') ?? 0);
    }

    public function test_an_order_becomes_a_pick_list_with_only_what_is_still_owed(): void
    {
        $done = $this->product('KEEP-DONE');
        $owed = $this->product('KEEP-OWED');

        $order = $this->order([
            [$done, 5, 5],   // already delivered — nothing to pick
            [$owed, 7, 2],   // five still owed
        ]);

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.store'), [
                'warehouse_id' => $this->warehouse->id,
                'sales_order_id' => $order->id,
            ])
            ->assertSessionHasNoErrors();

        $list = $this->list();

        $this->assertSame(PickList::STATUS_DRAFT, $list->status);
        $this->assertSame($order->id, (int) $list->sales_order_id);
        $this->assertCount(1, $list->lines);
        $this->assertSame($owed->id, (int) $list->lines->first()->product_id);
        $this->assertSame(5.0, (float) $list->lines->first()->quantity);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.pick_list_created']);
    }

    public function test_every_line_names_the_bin_to_walk_to(): void
    {
        $product = $this->product('BIN-1');
        $bin = $this->bin('A-01');
        $this->assign($product, $bin);

        $order = $this->order([[$product, 2, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ]);

        $line = $this->list()->lines->first();

        $this->assertSame($bin->id, (int) $line->warehouse_bin_id);
        $this->assertTrue($line->hasBin());

        // A product nobody has placed gets a line that says so, not a guess.
        $homeless = $this->product('BIN-NONE');
        $second = $this->order([[$homeless, 1, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $second->id,
        ]);

        $this->assertFalse($this->list()->lines->first()->hasBin());
    }

    public function test_a_batch_tracked_product_is_sent_to_its_earliest_expiry(): void
    {
        $product = $this->product('BATCHED', true);

        $this->receive($product, 5, 'LOT-LATE', now()->addDays(120)->toDateString());
        $this->receive($product, 5, 'LOT-SOON', now()->addDays(10)->toDateString());

        $order = $this->order([[$product, 3, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ]);

        $line = $this->list()->lines->first();

        $this->assertNotNull($line->stock_batch_id);
        $this->assertSame('LOT-SOON', $line->batch->batch_no);

        // A product nobody tracks batches for is never told which batch to take:
        // there is nothing to tell it.
        $plain = $this->product('NOT-BATCHED');
        $this->receive($plain, 4, 'IGNORED-LOT', now()->addDays(5)->toDateString());

        $second = $this->order([[$plain, 1, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $second->id,
        ]);

        $this->assertNull($this->list()->lines->first()->stock_batch_id);
    }

    public function test_nobody_can_pick_more_than_the_sheet_asked_for(): void
    {
        $product = $this->product('OVER-PICK');
        $order = $this->order([[$product, 3, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ]);

        $list = $this->list();
        $line = $list->lines->first();

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.pick', $list), ['picked' => [$line->id => 7]])
            ->assertSessionHasErrors('picked');

        $this->assertSame(0.0, (float) $line->refresh()->picked_quantity);
        $this->assertSame(PickList::STATUS_DRAFT, $list->refresh()->status);
    }

    public function test_an_empty_box_means_not_finished_and_only_what_is_written_moves(): void
    {
        $product = $this->product('PARTIAL');
        $order = $this->order([[$product, 10, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ]);

        $list = $this->list();
        $line = $list->lines->first();

        // Four of ten, and a note about the rest.
        $this->actingAs($this->admin)->post(route('inventory.pick-lists.pick', $list), [
            'picked' => [$line->id => 4],
            'line_notes' => [$line->id => 'Six are still on the inbound pallet.'],
        ])->assertSessionHasNoErrors();

        $line->refresh();
        $list->refresh();

        $this->assertSame(4.0, (float) $line->picked_quantity);
        $this->assertSame('Six are still on the inbound pallet.', $line->note);
        $this->assertSame(PickList::STATUS_PICKING, $list->status);
        $this->assertNotNull($list->started_at);
        $this->assertSame(6.0, $line->shortfall());
        $this->assertSame(40, $list->progressPct());

        // Saving again without touching the box keeps the four that are written.
        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.pick', $list), ['picked' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame(4.0, (float) $line->refresh()->picked_quantity);
    }

    public function test_finishing_the_walk_moves_the_order_and_touches_no_stock(): void
    {
        $product = $this->product('FINISH-1');
        $this->receive($product, 20);

        $order = $this->order([[$product, 6, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ]);

        $list = $this->list();

        $movements = StockMovement::query()->count();
        $onHand = $this->onHand($product);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.pick', $list), [
            'picked' => [$list->lines->first()->id => 6],
        ]);

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.complete', $list))
            ->assertSessionHasNoErrors();

        $list->refresh();

        $this->assertSame(PickList::STATUS_PICKED, $list->status);
        $this->assertNotNull($list->completed_at);
        $this->assertSame('ready_to_ship', $order->refresh()->status);

        // The goods are off the shelf; the books have not heard about it yet.
        $this->assertSame($movements, StockMovement::query()->count());
        $this->assertSame($onHand, $this->onHand($product), 'picking and finishing a walk must not move a single unit');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.pick_completed']);
    }

    public function test_a_walk_cannot_be_finished_before_anything_is_picked(): void
    {
        $product = $this->product('EMPTY-WALK');
        $order = $this->order([[$product, 2, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ]);

        $list = $this->list();

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.complete', $list))
            ->assertSessionHasErrors('complete');

        $this->assertSame(PickList::STATUS_DRAFT, $list->refresh()->status);
        $this->assertSame('confirmed', $order->refresh()->status);
    }

    public function test_one_order_has_one_open_walk(): void
    {
        $product = $this->product('ONE-WALK');
        $order = $this->order([[$product, 4, 0]]);

        $this->actingAs($this->admin)->post(route('inventory.pick-lists.store'), [
            'warehouse_id' => $this->warehouse->id,
            'sales_order_id' => $order->id,
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.store'), [
                'warehouse_id' => $this->warehouse->id,
                'sales_order_id' => $order->id,
            ])
            ->assertSessionHasErrors('pick_list');

        $this->assertSame(1, PickList::query()->count());

        // Abandoning the first one frees the order for a new list — the goods are
        // still owed to the customer either way.
        $list = $this->list();

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.cancel', $list), [])
            ->assertSessionHasErrors('cancel_reason');

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.cancel', $list), ['cancel_reason' => 'Pallets never arrived.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PickList::STATUS_CANCELLED, $list->refresh()->status);
        $this->assertSame('Pallets never arrived.', $list->cancel_reason);

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.store'), [
                'warehouse_id' => $this->warehouse->id,
                'sales_order_id' => $order->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PickList::query()->count());
    }

    public function test_a_manual_list_is_just_as_real_as_an_order(): void
    {
        $first = $this->product('MANUAL-1');
        $second = $this->product('MANUAL-2');

        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.store'), [
                'warehouse_id' => $this->warehouse->id,
                'lines' => [
                    ['product_id' => $first->id, 'quantity' => 2],
                    ['product_id' => $second->id, 'quantity' => 3],
                    ['product_id' => $first->id, 'quantity' => 1], // same product twice adds up
                    ['product_id' => '', 'quantity' => ''],
                ],
            ])
            ->assertSessionHasNoErrors();

        $list = $this->list();

        $this->assertNull($list->sales_order_id);
        $this->assertCount(2, $list->lines);
        $this->assertSame(3.0, (float) $list->lines->firstWhere('product_id', $first->id)->quantity);

        // An empty form is not a walk: it is a wasted trip.
        $this->actingAs($this->admin)
            ->post(route('inventory.pick-lists.store'), ['warehouse_id' => $this->warehouse->id])
            ->assertSessionHasErrors('lines');
    }

    public function test_another_companys_walk_is_not_found(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = PickList::create([
            'company_id' => $otherCompany,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'PL-OTHER',
            'status' => PickList::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.pick-lists.show', $list))
            ->assertNotFound();
    }

    /**
     * A walk typed by hand is typed from *this* company's catalogue. The product
     * picker is filtered to the tenant, so a neighbouring company's product
     * cannot be walked into a list here even by a hand-typed code.
     */
    public function test_the_manual_rows_only_offer_this_companys_products(): void
    {
        $mine = $this->product('PICK-MINE');

        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $theirs = Product::create([
            'company_id' => $otherCompany,
            'code' => 'PICK-THEIRS',
            'sku' => 'PICK-THEIRS',
            'name' => 'Not our product',
            'cost_method' => 'fifo',
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.pick-lists.create'))
            ->assertOk()
            ->assertSee($mine->sku)
            ->assertDontSee($theirs->sku);
    }
}
