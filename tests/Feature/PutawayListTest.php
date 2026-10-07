<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ProductBinAssignment;
use App\Domain\Inventory\PutawayList;
use App\Domain\Inventory\Services\WarehouseService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\WarehouseBin;
use App\Domain\Inventory\WarehouseZone;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\GoodsReceipt;
use App\Domain\Purchase\Models\GoodsReceiptLine;
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
 * §04-44 — putaway lists.
 *
 * What this pins:
 *  · a putaway starts from a posted receipt — the paper that brought the stock
 *    in — and a receipt cannot be put away twice while a live list exists;
 *  · placing goods writes the bin map, and the *first* home a product gets
 *    becomes its pick face; a later pallet landing elsewhere does not move it;
 *  · a quantity without a bin is refused: the map is the only thing bin rows
 *    are for, and a placement that says nothing teaches it nothing;
 *  · a putaway is finished only when the dock is empty, and like a pick it never
 *    moves stock — the receipt already did that when it was posted.
 */
class PutawayListTest extends TestCase
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
        $request = Request::create('/__putaway', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function product(string $sku): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Putaway probe '.$sku,
            'cost_method' => 'fifo',
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());
    }

    protected function bin(string $code, string $zoneCode = 'STOR', string $purpose = 'storage'): WarehouseBin
    {
        $zones = app(WarehouseService::class);

        $zone = WarehouseZone::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('code', $zoneCode)
            ->first()
            ?? $zones->createZone($this->warehouse, ['code' => $zoneCode, 'name' => $zoneCode, 'type' => $purpose], $this->admin);

        return $zones->createBin($zone, ['code' => $code, 'name' => $code], $this->admin);
    }

    protected function supplier(): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['company_id' => $this->admin->company_id, 'code' => 'SUP-TEST'],
            ['name' => 'Test supplier', 'is_active' => true],
        );
    }

    /** A receipt with the given [product, qty, batch no] lines. */
    protected function receipt(array $lines, string $status = 'posted'): GoodsReceipt
    {
        $receipt = GoodsReceipt::create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->default_branch_id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier()->id,
            'code' => 'GRN-'.uniqid(),
            'received_date' => now()->toDateString(),
            'status' => $status,
            'received_by' => $this->admin->id,
            'posted_at' => $status === 'posted' ? now() : null,
            'posted_by' => $status === 'posted' ? $this->admin->id : null,
        ]);

        $sort = 0;

        foreach ($lines as [$product, $qty, $batchNo]) {
            $sort++;

            GoodsReceiptLine::create([
                'goods_receipt_id' => $receipt->id,
                'product_id' => $product->id,
                'qty_received' => $qty,
                'unit_cost' => 10,
                'line_total' => $qty * 10,
                'batch_no' => $batchNo,
                'sort_order' => $sort,
            ]);
        }

        return $receipt->load('lines');
    }

    protected function list(): PutawayList
    {
        return PutawayList::query()->latest('id')->firstOrFail();
    }

    public function test_a_posted_receipt_becomes_a_putaway_list(): void
    {
        $first = $this->product('PA-1');
        $second = $this->product('PA-2');

        $receipt = $this->receipt([
            [$first, 10, 'LOT-PA-1'],
            [$second, 4, null],
        ]);

        // The batch is on the shelf because the goods came in through the ledger —
        // a putaway list links to the batch that already exists, it never invents one.
        app(StockLedgerService::class)->post([
            'product_id' => $first->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => 10,
            'unit_cost' => 10,
            'batch_no' => 'LOT-PA-1',
            'idempotency_key' => uniqid('putaway-recv-', true),
        ], $this->admin);

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['goods_receipt_id' => $receipt->id])
            ->assertSessionHasNoErrors();

        $list = $this->list();

        $this->assertSame(PutawayList::STATUS_DRAFT, $list->status);
        $this->assertSame($receipt->id, (int) $list->goods_receipt_id);
        $this->assertSame($this->warehouse->id, (int) $list->warehouse_id);
        $this->assertCount(2, $list->lines);
        $this->assertSame(10.0, (float) $list->lines->first()->quantity);
        $this->assertSame('LOT-PA-1', $list->lines->first()->batch?->batch_no);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.putaway_list_created']);
    }

    public function test_an_unposted_receipt_has_nothing_to_put_away(): void
    {
        $product = $this->product('PA-DRAFT');
        $receipt = $this->receipt([[$product, 3, null]], status: 'draft');

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['goods_receipt_id' => $receipt->id])
            ->assertSessionHasErrors('putaway_list');

        $this->assertSame(0, PutawayList::query()->count());
    }

    public function test_a_receipt_cannot_be_put_away_twice(): void
    {
        $product = $this->product('PA-TWICE');
        $receipt = $this->receipt([[$product, 2, null]]);

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['goods_receipt_id' => $receipt->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['goods_receipt_id' => $receipt->id])
            ->assertSessionHasErrors('putaway_list');

        $this->assertSame(1, PutawayList::query()->count());

        // Abandoning the list hands the receipt back: the goods are still there.
        $list = $this->list();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.cancel', $list), ['cancel_reason' => 'Wrong aisle, re-issuing.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['goods_receipt_id' => $receipt->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PutawayList::query()->count());
    }

    public function test_placing_goods_writes_the_first_home_into_the_bin_map(): void
    {
        $product = $this->product('PA-HOME');
        $receipt = $this->receipt([[$product, 6, null]]);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.store'), [
            'goods_receipt_id' => $receipt->id,
        ]);

        $list = $this->list();
        $line = $list->lines->first();
        $bin = $this->bin('B-01');

        $this->assertFalse($line->hasBin(), 'nothing suggests a bin for a product nobody has placed yet');

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.place', $list), [
            'placements' => [$line->id => ['bin_id' => $bin->id, 'qty' => 6]],
        ])->assertSessionHasNoErrors();

        $line->refresh();
        $list->refresh();

        $this->assertSame(6.0, (float) $line->placed_quantity);
        $this->assertSame($bin->id, (int) $line->placed_bin_id);
        $this->assertSame(PutawayList::STATUS_PUTTING_AWAY, $list->status);
        $this->assertNotNull($list->started_at);

        $assignment = ProductBinAssignment::query()
            ->where('product_id', $product->id)
            ->where('warehouse_bin_id', $bin->id)
            ->first();

        $this->assertNotNull($assignment, 'placing goods must teach the map where the product lives');
        $this->assertTrue($assignment->is_primary, 'the first home becomes the pick face');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.putaway_recorded']);
    }

    public function test_a_later_pallet_does_not_move_the_pick_face(): void
    {
        $first = $this->product('PA-FACE');
        $second = $this->product('PA-SECOND');

        $receipt = $this->receipt([
            [$first, 5, null],
            [$second, 5, null],
        ]);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.store'), [
            'goods_receipt_id' => $receipt->id,
        ]);

        $list = $this->list();
        [$lineOne, $lineTwo] = [$list->lines[0], $list->lines[1]];

        $home = $this->bin('C-01');
        $over = $this->bin('C-02');

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.place', $list), [
            'placements' => [
                $lineOne->id => ['bin_id' => $home->id, 'qty' => 5],
                $lineTwo->id => ['bin_id' => $over->id, 'qty' => 5],
            ],
        ])->assertSessionHasNoErrors();

        // Same product arriving in a second bin: recorded, but the pick face stays.
        $extra = $this->receipt([[$first, 3, null]]);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.store'), [
            'goods_receipt_id' => $extra->id,
        ]);

        $secondList = $this->list();
        $secondLine = $secondList->lines->first();

        $this->assertSame($home->id, (int) $secondLine->warehouse_bin_id, 'the suggestion is the known home');

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.place', $secondList), [
            'placements' => [$secondLine->id => ['bin_id' => $over->id, 'qty' => 3]],
        ])->assertSessionHasNoErrors();

        $secondLine->refresh();

        $this->assertSame($over->id, (int) $secondLine->placed_bin_id);
        $this->assertSame($home->id, (int) $secondLine->warehouse_bin_id, 'the plan is not rewritten by the fact');

        $primaries = ProductBinAssignment::query()
            ->where('product_id', $first->id)
            ->where('is_primary', true)
            ->pluck('warehouse_bin_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertSame([$home->id], $primaries, 'one pick face per product per warehouse, and it did not move');
    }

    public function test_a_quantity_without_a_bin_is_refused(): void
    {
        $product = $this->product('PA-NOBIN');
        $receipt = $this->receipt([[$product, 4, null]]);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.store'), [
            'goods_receipt_id' => $receipt->id,
        ]);

        $list = $this->list();
        $line = $list->lines->first();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.place', $list), [
                'placements' => [$line->id => ['qty' => 4]],
            ])
            ->assertSessionHasErrors('placements');

        $this->assertSame(0.0, (float) $line->refresh()->placed_quantity);
        $this->assertSame(0, ProductBinAssignment::query()->count());
    }

    public function test_a_putaway_is_finished_only_when_the_dock_is_empty(): void
    {
        $first = $this->product('PA-DOCK-1');
        $second = $this->product('PA-DOCK-2');
        $receipt = $this->receipt([[$first, 2, null], [$second, 2, null]]);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.store'), [
            'goods_receipt_id' => $receipt->id,
        ]);

        $list = $this->list();
        $bin = $this->bin('D-01');

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.place', $list), [
            'placements' => [$list->lines[0]->id => ['bin_id' => $bin->id, 'qty' => 2]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.complete', $list))
            ->assertSessionHasErrors('complete');

        $this->assertSame(PutawayList::STATUS_PUTTING_AWAY, $list->refresh()->status);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.place', $list), [
            'placements' => [$list->lines[1]->id => ['bin_id' => $bin->id, 'qty' => 2]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.complete', $list))
            ->assertSessionHasNoErrors();

        $list->refresh();

        $this->assertSame(PutawayList::STATUS_PUT_AWAY, $list->status);
        $this->assertNotNull($list->completed_at);
        $this->assertSame(100, $list->progressPct());
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.putaway_completed']);
    }

    public function test_putaway_never_moves_stock_and_never_exceeds_what_arrived(): void
    {
        $product = $this->product('PA-LEDGER');
        $receipt = $this->receipt([[$product, 8, null]]);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.store'), [
            'goods_receipt_id' => $receipt->id,
        ]);

        $list = $this->list();
        $line = $list->lines->first();
        $bin = $this->bin('E-01');

        $movements = StockMovement::query()->count();

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.place', $list), [
                'placements' => [$line->id => ['bin_id' => $bin->id, 'qty' => 9]],
            ])
            ->assertSessionHasErrors('placements');

        $this->assertSame(0.0, (float) $line->refresh()->placed_quantity);

        $this->actingAs($this->admin)->post(route('inventory.putaway-lists.place', $list), [
            'placements' => [$line->id => ['bin_id' => $bin->id, 'qty' => 3]],
        ])->assertSessionHasNoErrors();

        $this->assertSame($movements, StockMovement::query()->count(), 'putaway is a place, not a quantity');
        $this->assertSame(3.0, (float) $line->refresh()->placed_quantity);
        $this->assertSame(5.0, $line->shortfall());
    }

    public function test_a_manual_putaway_needs_a_warehouse_and_a_line(): void
    {
        $product = $this->product('PA-MANUAL');

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['lines' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertSessionHasErrors('warehouse_id');

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), ['warehouse_id' => $this->warehouse->id])
            ->assertSessionHasErrors('lines');

        $this->actingAs($this->admin)
            ->post(route('inventory.putaway-lists.store'), [
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['product_id' => $product->id, 'quantity' => 2]],
            ])
            ->assertSessionHasNoErrors();

        $list = $this->list();

        $this->assertNull($list->goods_receipt_id);
        $this->assertSame(2.0, (float) $list->lines->first()->quantity);
    }

    public function test_another_companys_putaway_is_not_found(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Co Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = PutawayList::create([
            'company_id' => $otherCompany,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'PA-OTHER',
            'status' => PutawayList::STATUS_DRAFT,
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.putaway-lists.show', $list))
            ->assertNotFound();
    }
}
