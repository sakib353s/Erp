<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Actions\PostStockAdjustment;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockCountService;
use App\Domain\Inventory\StockAdjustment;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockCount;
use App\Domain\Inventory\StockMovement;
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
 * §04-31 — stock count / cycle count.
 *
 * What this pins:
 *  · the sheet is a snapshot: the numbers the counter is asked about are frozen
 *    when the sheet opens, so a warehouse that keeps moving cannot quietly
 *    rewrite the question;
 *  · posting makes the counted figure the truth for the lines that were
 *    counted, measured against the balance *now* — not against the snapshot —
 *    so the shelf ends where the counter left it;
 *  · the difference is written as an ordinary stock adjustment, through the one
 *    adjustment path, with the sheet as its reason;
 *  · an uncounted line is not a zero, a cancelled sheet corrects nothing, and a
 *    posted sheet cannot be posted or edited again;
 *  · a counter who cannot post does not get to see the number they are meant to
 *    be checking — the screen is blind for them.
 */
class StockCountTest extends TestCase
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

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();
    }

    protected function request(): Request
    {
        $request = Request::create('/__counts', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function counts(): StockCountService
    {
        return app(StockCountService::class);
    }

    protected function stocked(string $sku, float $qty, float $cost = 50): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Count probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => $cost,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        if ($qty > 0) {
            app(CreateOpeningStock::class)->handle([
                'warehouse_id' => $this->warehouse->id,
                'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => $cost]],
                'idempotency_suffix' => uniqid('count-', true),
            ], $this->request());
        }

        return $product;
    }

    protected function onHand(Product $product): float
    {
        return (float) (StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->value('on_hand') ?? 0);
    }

    protected function lineFor(StockCount $count, Product $product)
    {
        return $count->lines()->where('product_id', $product->id)->firstOrFail();
    }

    public function test_opening_a_full_sheet_freezes_what_the_ledger_says_today(): void
    {
        $counted = $this->stocked('CNT-A', 12);
        $neverMoved = $this->stocked('CNT-B', 0);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'count_date' => now()->toDateString(),
            'scope' => StockCount::SCOPE_FULL,
            'notes' => 'Quarterly count, aisle 1-4.',
        ], $this->admin);

        $this->assertSame(StockCount::STATUS_COUNTING, $sheet->status);
        $this->assertNotNull($sheet->code);
        $this->assertSame('12.0000', (string) $this->lineFor($sheet, $counted)->system_qty);

        // A product the ledger thinks is at zero is on the sheet too: finding
        // stock nobody expected is half the reason to count.
        $this->assertSame('0.0000', (string) $this->lineFor($sheet, $neverMoved)->system_qty);
        $this->assertNull($this->lineFor($sheet, $counted)->counted_qty);

        // The snapshot does not follow the warehouse.
        app(PostStockAdjustment::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'reason' => 'Stock arrived mid-count',
            'lines' => [['product_id' => $counted->id, 'qty_delta' => 5, 'unit_cost' => 50]],
        ], $this->request());

        $this->assertSame('12.0000', (string) $this->lineFor($sheet, $counted)->system_qty, 'the sheet still asks about 12');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'inventory.count_opened',
            'entity_id' => $sheet->id,
        ]);
    }

    public function test_a_cycle_count_only_asks_about_the_products_chosen(): void
    {
        $chosen = $this->stocked('CNT-C1', 4);
        $chosen2 = $this->stocked('CNT-C2', 6);
        $this->stocked('CNT-C3', 8);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$chosen->id, $chosen2->id],
        ], $this->admin);

        $this->assertSame(2, $sheet->line_count);
        $this->assertEqualsCanonicalizing(
            [$chosen->id, $chosen2->id],
            $sheet->lines->pluck('product_id')->all(),
        );

        // And it refuses to open an empty one.
        $this->expectException(RuntimeException::class);
        $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [],
        ], $this->admin);
    }

    public function test_posting_makes_the_counted_figure_the_truth_and_writes_one_adjustment(): void
    {
        $short = $this->stocked('CNT-D1', 20);
        $over = $this->stocked('CNT-D2', 5);
        $agreed = $this->stocked('CNT-D3', 7);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$short->id, $over->id, $agreed->id],
        ], $this->admin);

        $sheet = $this->counts()->recordCounts($sheet, [
            $short->id => 17,   // three missing
            $over->id => 9,     // four more than the ledger thought
            $agreed->id => 7,   // agreed
        ], $this->admin);

        $this->assertSame(3, $sheet->counted_lines);
        $this->assertSame(2, $sheet->variance_lines);
        $this->assertSame('-3.0000', (string) $this->lineFor($sheet, $short)->variance);

        $posted = $this->counts()->post($sheet, $this->admin);

        $this->assertSame(StockCount::STATUS_POSTED, $posted->status);
        $this->assertSame(17.0, $this->onHand($short));
        $this->assertSame(9.0, $this->onHand($over));
        $this->assertSame(7.0, $this->onHand($agreed));
        $this->assertSame(2, $posted->variance_lines);

        // The movements are ordinary adjustments, with the sheet as their reason.
        $adjustment = StockAdjustment::query()->findOrFail($posted->stock_adjustment_id);
        $this->assertSame(2, $adjustment->lines()->count());
        $this->assertStringContainsString($posted->code, $adjustment->reason);
        $this->assertStringContainsString('count variance', $adjustment->reason);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $short->id,
            'movement_type' => StockMovement::TYPE_ADJUST_OUT,
            'source_type' => 'stock_adjustment',
            'source_id' => $adjustment->id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $over->id,
            'movement_type' => StockMovement::TYPE_ADJUST_IN,
        ]);
        $this->assertSame(0, StockMovement::query()
            ->where('source_id', $adjustment->id)
            ->where('product_id', $agreed->id)
            ->count(), 'a line that agreed writes no movement at all');

        // 3 × 50 out, 4 × 50 in — the sheet says what the ledger moved and why.
        $this->assertSame('-150.0000', (string) $this->lineFor($posted, $short)->value);
        $this->assertSame('200.0000', (string) $this->lineFor($posted, $over)->value);
        $this->assertSame('50.0000', (string) $posted->variance_value);
        $this->assertSame(1.0, (float) $adjustment->lines()->sum('qty_delta'), 'three out, four in — one unit net');
        $this->assertSame(50.0, (float) $posted->variance_value, 'and that one unit is worth 50 at cost');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'inventory.count_posted',
            'entity_id' => $posted->id,
        ]);
    }

    public function test_the_count_is_measured_against_stock_now_not_the_snapshot(): void
    {
        $product = $this->stocked('CNT-E1', 10);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$product->id],
        ], $this->admin);

        // The counter says 8, and while they were counting four units left the
        // warehouse for a sale. The shelf holds what the counter says it holds.
        $this->counts()->recordCounts($sheet, [$product->id => 8], $this->admin);

        app(PostStockAdjustment::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'reason' => 'Picked during the count',
            'lines' => [['product_id' => $product->id, 'qty_delta' => -4, 'unit_cost' => 50]],
        ], $this->request());

        $posted = $this->counts()->post($sheet->refresh(), $this->admin);
        $line = $this->lineFor($posted, $product);

        $this->assertSame(8.0, $this->onHand($product), 'the count is authoritative');
        $this->assertSame('-2.0000', (string) $line->variance, 'against the snapshot the shelf lost 2');
        $this->assertSame('2.0000', (string) $line->posted_delta, 'but 2 had to come back to reach the counted figure');
    }

    public function test_an_uncounted_line_is_not_a_zero_and_nothing_happens_without_counts(): void
    {
        $counted = $this->stocked('CNT-F1', 9);
        $untouched = $this->stocked('CNT-F2', 6);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$counted->id, $untouched->id],
        ], $this->admin);

        // Posting a sheet nobody counted is refused, not quietly accepted.
        try {
            $this->counts()->post($sheet, $this->admin);
            $this->fail('A sheet with nothing counted must not post.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nothing has been counted', $e->getMessage());
        }

        $sheet = $this->counts()->recordCounts($sheet, [$counted->id => 4], $this->admin);
        $this->assertSame(1, $sheet->counted_lines);

        $posted = $this->counts()->post($sheet, $this->admin);

        $this->assertSame(4.0, $this->onHand($counted));
        $this->assertSame(6.0, $this->onHand($untouched), 'an uncounted line is left exactly as it was');
        $this->assertNull($this->lineFor($posted, $untouched)->counted_qty);

        // Counting a line and then clearing the box puts it back to uncounted.
        $sheet2 = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$counted->id],
        ], $this->admin);

        $sheet2 = $this->counts()->recordCounts($sheet2, [$counted->id => 3], $this->admin);
        $this->assertSame(1, $sheet2->counted_lines);

        $sheet2 = $this->counts()->recordCounts($sheet2, [$counted->id => ''], $this->admin);
        $this->assertSame(0, $sheet2->counted_lines);
        $this->assertNull($this->lineFor($sheet2, $counted)->counted_qty);
    }

    public function test_a_cancelled_sheet_corrects_nothing_and_a_posted_one_is_closed(): void
    {
        $product = $this->stocked('CNT-G1', 15);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$product->id],
        ], $this->admin);

        $this->counts()->recordCounts($sheet, [$product->id => 11], $this->admin);
        $cancelled = $this->counts()->cancel($sheet, 'Counting the wrong aisle.', $this->admin);

        $this->assertSame(StockCount::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('Counting the wrong aisle.', $cancelled->cancel_note);
        $this->assertSame(15.0, $this->onHand($product), 'a cancelled count corrects nothing');
        $this->assertNull($cancelled->stock_adjustment_id);

        try {
            $this->counts()->post($cancelled->refresh(), $this->admin);
            $this->fail('A cancelled sheet must not post.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }

        // A posted sheet is closed for good.
        $live = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$product->id],
        ], $this->admin);

        $live = $this->counts()->recordCounts($live, [$product->id => 14], $this->admin);
        $posted = $this->counts()->post($live, $this->admin);

        $this->assertSame(14.0, $this->onHand($product));

        try {
            $this->counts()->post($posted->refresh(), $this->admin);
            $this->fail('A posted sheet must not post twice.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('posted', $e->getMessage());
        }

        try {
            $this->counts()->recordCounts($posted->refresh(), [$product->id => 1], $this->admin);
            $this->fail('A posted sheet must not be edited.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('posted', $e->getMessage());
        }
    }

    public function test_the_screens_are_gated_and_a_counter_cannot_see_what_is_expected(): void
    {
        $product = $this->stocked('CNT-H1', 20);

        $sheet = $this->counts()->open([
            'warehouse_id' => $this->warehouse->id,
            'scope' => StockCount::SCOPE_CYCLE,
            'products' => [$product->id],
        ], $this->admin);

        $this->actingAs($this->admin)->get('/app/inventory/counts')->assertOk()->assertSee($sheet->code);
        $this->actingAs($this->admin)->get('/app/inventory/counts/create')->assertOk();

        // The manager sees the number they are checking against.
        $this->actingAs($this->admin)
            ->get(route('inventory.counts.show', $sheet))
            ->assertOk()
            ->assertSee('Ledger said')
            ->assertSee('20.0000');

        // The counter does not — a blind sheet is written down blind.
        $counter = $this->makeUser(['name' => 'Aisle Counter']);
        $counter->roles()->attach($this->roleWith([
            'portal.erp.access', 'dashboard.view', 'inventory.counts.view', 'inventory.counts.create',
        ])->id);

        $this->actingAs($counter)
            ->get(route('inventory.counts.show', $sheet))
            ->assertOk()
            ->assertDontSee('Ledger said')
            ->assertDontSee('20.0000')
            ->assertSee('Blind count');

        // And they cannot post the difference into stock.
        $this->counts()->recordCounts($sheet, [$product->id => 18], $counter);

        $this->actingAs($counter)
            ->post(route('inventory.counts.post', $sheet))
            ->assertForbidden();

        $this->assertSame(20.0, $this->onHand($product));

        // The manager posts it, over HTTP.
        $this->actingAs($this->admin)
            ->from(route('inventory.counts.show', $sheet))
            ->post(route('inventory.counts.post', $sheet))
            ->assertRedirect(route('inventory.counts.show', $sheet));

        $this->assertSame(18.0, $this->onHand($product));

        // Someone with no count permission sees nothing at all.
        $outsider = $this->makeUser(['name' => 'No Access']);
        $outsider->roles()->attach($this->roleWith(['portal.erp.access', 'inventory.stock.view'])->id);

        $this->actingAs($outsider)->get('/app/inventory/counts')->assertForbidden();
        $this->actingAs($outsider)->get(route('inventory.counts.show', $sheet))->assertForbidden();
        $this->actingAs($outsider)->post(route('inventory.counts.post', $sheet))->assertForbidden();
    }

    public function test_a_sheet_can_be_opened_and_counted_over_http(): void
    {
        $product = $this->stocked('CNT-I1', 30);

        $this->actingAs($this->admin)
            ->post('/app/inventory/counts', [
                'warehouse_id' => $this->warehouse->id,
                'count_date' => now()->toDateString(),
                'scope' => StockCount::SCOPE_CYCLE,
                'notes' => 'Aisle 7, shelf B.',
                'products' => [$product->id],
            ])
            ->assertRedirect();

        $sheet = StockCount::query()->latest('id')->firstOrFail();
        $this->assertSame(StockCount::SCOPE_CYCLE, $sheet->scope);
        $this->assertSame(1, $sheet->line_count);
        $this->assertSame('30.0000', (string) $this->lineFor($sheet, $product)->system_qty);

        $this->actingAs($this->admin)
            ->post(route('inventory.counts.save', $sheet), ['counted' => [$product->id => 28]])
            ->assertRedirect();

        $this->assertSame('28.0000', (string) $this->lineFor($sheet->refresh(), $product)->counted_qty);

        // A count of a product nobody stocks is refused by validation.
        $this->actingAs($this->admin)
            ->post('/app/inventory/counts', [
                'warehouse_id' => $this->warehouse->id,
                'count_date' => now()->toDateString(),
                'scope' => StockCount::SCOPE_CYCLE,
                'products' => [999999],
            ])
            ->assertSessionHasErrors('products.0');

        // And a cycle count with no products is a validation error, not an empty sheet.
        $this->actingAs($this->admin)
            ->post('/app/inventory/counts', [
                'warehouse_id' => $this->warehouse->id,
                'count_date' => now()->toDateString(),
                'scope' => StockCount::SCOPE_CYCLE,
            ])
            ->assertSessionHasErrors('products');
    }
}
