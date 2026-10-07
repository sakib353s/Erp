<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ReorderPolicy;
use App\Domain\Inventory\ReorderSuggestion;
use App\Domain\Inventory\Services\ReorderService;
use App\Domain\Inventory\Services\ReorderSuggestionService;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Masters\Supplier;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Purchase\Services\PurchaseOrderService;
use App\Domain\Settings\Services\SettingService;
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
 * §04-55/04-56/04-58 — the reorder desk.
 *
 * What this pins:
 *  · demand is measured from real outbound movements only, over the window the
 *    settings name — a transfer, a write-off or a correction is not a customer;
 *  · the quantity follows one explainable ladder: the policy's own answer as a
 *    floor, demand over the lead time plus safety as the need, the maximum as a
 *    ceiling, and what is already on order subtracted — never bought twice;
 *  · a proposal is a **recorded decision**: it keeps the figures it was judged
 *    by, and looking again supersedes it instead of stacking a second row;
 *  · accepting drafts a purchase order and moves no stock or money at all, and
 *    an answered proposal cannot be answered twice.
 */
class ReorderSuggestionTest extends TestCase
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
        $request = Request::create('/__reorder', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function product(string $sku): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Reorder probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());
    }

    protected function stock(Product $product, float $qty): void
    {
        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => 100]],
            'idempotency_suffix' => uniqid('reorder-', true),
        ], $this->request());
    }

    /** A real outbound movement, dated whenever it happened. */
    protected function move(Product $product, string $type, float $qty, ?string $occurredAt = null): StockMovement
    {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => $type,
            'qty' => $qty,
            'idempotency_key' => uniqid('reorder-move-', true),
            'occurred_at' => $occurredAt,
        ], $this->admin);
    }

    protected function policy(Product $product, array $levels = []): ReorderPolicy
    {
        return app(ReorderService::class)->savePolicy($product, array_merge([
            'min_level' => 10,
            'max_level' => 0,
            'reorder_point' => 10,
            'safety_stock' => 10,
            'reorder_qty' => 0,
            'lead_time_days' => 7,
        ], $levels), null, $this->admin->id);
    }

    protected function supplier(): Supplier
    {
        return Supplier::query()->firstOrCreate(
            ['company_id' => $this->admin->company_id, 'code' => 'SUP-REORDER'],
            ['name' => 'Reorder supplier', 'is_active' => true],
        );
    }

    /** An open purchase order: stock that is already on its way. */
    protected function onOrder(Product $product, float $qty): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create([
            'supplier_id' => $this->supplier()->id,
            'warehouse_id' => $this->warehouse->id,
            'branch_id' => $this->admin->default_branch_id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'qty_ordered' => $qty,
                'unit_price' => 100,
            ]],
        ], $this->admin->id);
    }

    /* ------------------------------------------------------------------ tests --- */

    public function test_a_shelf_without_a_policy_is_never_proposed(): void
    {
        $watched = $this->product('REORDER-WATCHED');
        $unwatched = $this->product('REORDER-UNWATCHED');

        $this->stock($watched, 5);
        $this->stock($unwatched, 5);

        $this->policy($watched);

        $look = app(ReorderSuggestionService::class)->candidates();
        $skus = array_map(fn ($row) => $row['product']->sku, $look['rows']);

        $this->assertContains('REORDER-WATCHED', $skus);
        $this->assertNotContains('REORDER-UNWATCHED', $skus, 'no line drawn means no claim on the buyer');
        $this->assertSame(1, $look['counts']['short']);
    }

    public function test_demand_is_measured_from_real_outbound_movements_only(): void
    {
        $product = $this->product('REORDER-DEMAND');

        // Sold 300 over the window; 500 was sold before it and is none of this
        // window's business; a correction and a write-off are not customers.
        $this->stock($product, 955);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 500, now()->subDays(40)->toDateTimeString());
        $this->move($product, StockMovement::TYPE_ADJUST_OUT, 100);
        $this->move($product, StockMovement::TYPE_WRITE_OFF, 50);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);

        $balance = StockBalance::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();

        $this->assertSame(5.0, (float) $balance->on_hand, 'an old sale, an adjustment and a write-off still left the shelf');

        $demand = app(ReorderService::class)->demandIndex([[$product->id, $this->warehouse->id]]);
        $figures = $demand[$product->id.':'.$this->warehouse->id] ?? null;

        $this->assertNotNull($figures, 'the shelf that moved has a demand figure');
        $this->assertSame(10.0, $figures['avg_daily'], '300 units over a 30 day window is 10 a day');
        $this->assertSame(300.0, $figures['out_qty']);
        $this->assertSame(30, $figures['days']);

        $this->policy($product);
        $rows = app(ReorderService::class)->alertRows('low');
        $row = collect($rows['rows'])->firstWhere(fn ($candidate) => $candidate['product']->sku === 'REORDER-DEMAND');

        $this->assertNotNull($row, 'five on hand against a minimum of ten is low');
        $this->assertSame(10.0, $row['avg_daily_demand']);
        $this->assertSame(30, $row['demand_days']);
        $this->assertSame(0.5, $row['days_cover'], 'five units at ten a day is half a day of cover');
    }

    public function test_the_quantity_follows_one_ladder_and_never_buys_what_is_already_coming(): void
    {
        $product = $this->product('REORDER-LADDER');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);

        $this->policy($product);
        $service = app(ReorderSuggestionService::class);

        $row = $service->candidates()['rows'][0];

        // available 5, trigger 10 → 5 short; demand over the 7 day lead time is
        // 70; safety 10 has to still be on the shelf when it lands → 75.
        $this->assertSame(5.0, $row['available']);
        $this->assertSame(10.0, $row['trigger_qty']);
        $this->assertSame(5.0, $row['shortage']);
        $this->assertSame(70.0, $row['demand_qty']);
        $this->assertSame(75.0, $row['suggested_qty']);

        // Somebody already ordered 15 of them: the proposal is reduced, not repeated.
        $this->onOrder($product, 15);
        $row = $service->candidates()['rows'][0];

        $this->assertSame(15.0, $row['in_transit']);
        $this->assertSame(60.0, $row['suggested_qty']);

        // A maximum is a ceiling: 50 is the most this shelf may hold.
        $this->policy($product, ['max_level' => 50]);
        $row = $service->candidates()['rows'][0];

        $this->assertSame(30.0, $row['suggested_qty'], 'capped at 50 − 5 available, less the 15 on order');
    }

    public function test_recording_the_look_writes_the_figures_down_before_anything_is_bought(): void
    {
        $product = $this->product('REORDER-RECORD');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short'])
            ->assertRedirect(route('inventory.reorder.suggestions.index'))
            ->assertSessionHas('status');

        $suggestion = ReorderSuggestion::query()->firstOrFail();

        $this->assertStringStartsWith('RS/', $suggestion->code);
        $this->assertSame(ReorderSuggestion::STATUS_SUGGESTED, $suggestion->status);
        $this->assertSame(75.0, (float) $suggestion->suggested_qty);
        $this->assertSame(10.0, (float) $suggestion->avg_daily_demand);
        $this->assertSame(5.0, (float) $suggestion->available);
        $this->assertSame(10.0, (float) $suggestion->trigger_qty);
        $this->assertSame(30, $suggestion->demand_window_days);
        $this->assertIsArray($suggestion->inputs);
        $this->assertArrayHasKey('formula', $suggestion->inputs);
        $this->assertSame(10.0, (float) $suggestion->inputs['avg_daily_demand']);

        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.reorder_suggested']);

        // Nothing was bought by looking: no order, no movement, no money.
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, ReorderSuggestion::query()->where('status', ReorderSuggestion::STATUS_ACCEPTED)->count());
    }

    public function test_ticking_a_shelf_writes_down_that_shelf_and_not_the_whole_short_list(): void
    {
        $ticked = $this->product('REORDER-TICKED');
        $ignored = $this->product('REORDER-IGNORED');

        $this->stock($ticked, 5);
        $this->stock($ignored, 5);
        $this->policy($ticked);
        $this->policy($ignored);

        $this->assertSame(2, app(ReorderSuggestionService::class)->candidates()['counts']['short']);

        // A shelf is `product:warehouse` — ticking one product must not write
        // down the same product on another shelf, and an unticked row is not work.
        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), [
                'scope' => 'selected',
                'rows' => [$ticked->id.':'.$this->warehouse->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ReorderSuggestion::query()->count());
        $this->assertSame($ticked->id, (int) ReorderSuggestion::query()->firstOrFail()->product_id);

        // Ticking nothing is refused with a sentence rather than a silent no-op.
        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), ['scope' => 'selected', 'rows' => []])
            ->assertSessionHasErrors('rows');

        // A key that is not a shelf at all never reaches the service.
        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), ['scope' => 'selected', 'rows' => ['not-a-shelf']])
            ->assertSessionHasErrors('rows.0');
    }

    public function test_looking_again_supersedes_the_proposal_rather_than_stacking_a_second_one(): void
    {
        $product = $this->product('REORDER-AGAIN');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short'])
            ->assertSessionHasNoErrors();

        $first = ReorderSuggestion::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ReorderSuggestion::query()->count());
        $this->assertSame(ReorderSuggestion::STATUS_SUPERSEDED, $first->fresh()->status, 'the earlier figure keeps its numbers but stops asking');
        $this->assertNotNull($first->fresh()->decided_at);

        $open = ReorderSuggestion::query()->open()->get();
        $this->assertCount(1, $open);
        $this->assertSame(75.0, (float) $open->first()->suggested_qty);

        $this->actingAs($this->admin)
            ->get(route('inventory.reorder.history'))
            ->assertOk()
            ->assertSee('RS/');
    }

    public function test_a_shelf_covered_by_an_open_order_is_shown_but_never_proposed(): void
    {
        $product = $this->product('REORDER-COVERED');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);
        $this->onOrder($product, 200);

        $service = app(ReorderSuggestionService::class);
        $look = $service->candidates();
        $row = $look['rows'][0];

        $this->assertTrue($row['covered_by_order'], 'it is still below its trigger, and the desk says why nothing is needed');
        $this->assertSame(0.0, $row['suggested_qty']);
        $this->assertSame(0, $look['counts']['short'], 'nothing is waiting for a buyer');
        $this->assertSame(1, $look['counts']['covered'], 'the shelf is counted as already on its way, not as work');

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short'])
            ->assertSessionHas('status');

        $this->assertSame(0, ReorderSuggestion::query()->count(), 'a proposal to buy nothing is paperwork, not a decision');
    }

    public function test_accepting_drafts_a_purchase_order_and_moves_no_stock(): void
    {
        $product = $this->product('REORDER-ACCEPT');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $this->actingAs($this->admin)->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short']);

        $suggestion = ReorderSuggestion::query()->firstOrFail();

        $movements = StockMovement::query()->count();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.accept', $suggestion), [
                'supplier_id' => $this->supplier()->id,
            ])
            ->assertRedirect();

        $suggestion->refresh();
        $order = $suggestion->purchaseOrder;

        $this->assertNotNull($order, 'the proposal points at the order it produced');
        $this->assertSame('draft', $order->status, 'a draft — never posted, and still needing approval');
        $this->assertSame(ReorderSuggestion::STATUS_ACCEPTED, $suggestion->status);
        $this->assertNull($suggestion->final_qty, 'nobody changed the number, so the suggested figure stands');
        $this->assertSame(75.0, (float) $order->lines()->first()->qty_ordered);
        $this->assertSame($product->id, (int) $order->lines()->first()->product_id);
        $this->assertStringContainsString($suggestion->code, (string) $order->notes, 'the order says which figures asked for it');

        $this->assertSame($movements, StockMovement::query()->count(), 'drafting an order does not move stock');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.reorder_suggestion_accepted']);
    }

    public function test_a_person_may_change_the_quantity_and_the_proposal_records_both_numbers(): void
    {
        $product = $this->product('REORDER-QTY');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $this->actingAs($this->admin)->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short']);

        $suggestion = ReorderSuggestion::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.accept', $suggestion), [
                'supplier_id' => $this->supplier()->id,
                'quantity' => 40,
            ])
            ->assertRedirect();

        $suggestion->refresh();

        $this->assertSame(40.0, (float) $suggestion->final_qty);
        $this->assertSame(75.0, (float) $suggestion->suggested_qty, 'what the system said is kept beside what a person decided');
        $this->assertSame(40.0, $suggestion->effectiveQty());
        $this->assertSame(40.0, (float) $suggestion->purchaseOrder->lines()->first()->qty_ordered);
    }

    public function test_an_answered_proposal_cannot_be_answered_twice(): void
    {
        $product = $this->product('REORDER-TWICE');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $this->actingAs($this->admin)->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short']);

        $suggestion = ReorderSuggestion::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.accept', $suggestion), ['supplier_id' => $this->supplier()->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.accept', $suggestion), ['supplier_id' => $this->supplier()->id])
            ->assertSessionHasErrors('accept');

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.dismiss', $suggestion), ['note' => 'Changed my mind'])
            ->assertSessionHasErrors('dismiss');

        $this->assertSame(1, PurchaseOrder::query()->count(), 'one proposal, one order');
    }

    public function test_dismissing_needs_a_reason_and_keeps_it_with_the_figures(): void
    {
        $product = $this->product('REORDER-DISMISS');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $this->actingAs($this->admin)->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short']);

        $suggestion = ReorderSuggestion::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.dismiss', $suggestion), [])
            ->assertSessionHasErrors('note');

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.dismiss', $suggestion), ['note' => 'We are switching to another product'])
            ->assertRedirect(route('inventory.reorder.suggestions.index'));

        $suggestion->refresh();

        $this->assertSame(ReorderSuggestion::STATUS_DISMISSED, $suggestion->status);
        $this->assertSame('We are switching to another product', $suggestion->decision_note);
        $this->assertSame($this->admin->id, (int) $suggestion->decided_by);
        $this->assertSame(75.0, (float) $suggestion->suggested_qty, 'the figures stay on the row — that is the history');

        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.reorder_suggestion_dismissed']);

        $this->actingAs($this->admin)
            ->get(route('inventory.reorder.history'))
            ->assertOk()
            ->assertSee('We are switching to another product');

        $this->assertCount(0, ReorderSuggestion::query()->open()->get(), 'the desk no longer asks');
    }

    public function test_the_settings_the_desk_reads_are_real_switches(): void
    {
        $product = $this->product('REORDER-SETTINGS');

        $this->stock($product, 305);
        $this->move($product, StockMovement::TYPE_SALES_OUT, 300);
        $this->policy($product);

        $settings = app(SettingService::class);
        $service = app(ReorderSuggestionService::class);

        $this->assertSame(30, $service->windowDays());
        $this->assertSame(75.0, $service->candidates()['rows'][0]['suggested_qty'], 'demand leads the policy while the switches say so');

        $this->actingAs($this->admin)
            ->post(route('settings.update', 'reorder'), [
                'settings' => [
                    'demand_window_days' => 90,
                    'suggest_lead_time' => 0,
                    'strict_auto_mode' => 1,
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(90, app(ReorderSuggestionService::class)->windowDays(), 'the window is read, not hard-coded');

        // With demand held back, the proposal falls back to the policy's own
        // answer: 5 short of a trigger of 10, and nothing more. The window is
        // read too — the same 300 units over 90 days is a third of the average
        // day they were over 30, and that figure is stored on the row.
        $row = app(ReorderSuggestionService::class)->candidates()['rows'][0];

        $this->assertSame(5.0, $row['suggested_qty']);
        $this->assertSame(3.3333, round($row['avg_daily_demand'], 4));
        $this->assertSame(90, $row['inputs']['window_days']);

        $this->actingAs($this->admin)->post(route('inventory.reorder.suggestions.store'), ['scope' => 'short']);

        $suggestion = ReorderSuggestion::query()->firstOrFail();

        // Strict auto mode refuses to draft anything, so a switch labelled
        // "automatic" can never quietly raise a purchase order.
        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.accept', $suggestion), ['supplier_id' => $this->supplier()->id])
            ->assertSessionHasErrors('accept');

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertTrue($settings->getBool('reorder', 'strict_auto_mode'));
    }

    public function test_another_companys_proposal_is_not_found(): void
    {
        $otherCompany = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Buyers Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $suggestion = ReorderSuggestion::create([
            'company_id' => $otherCompany,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'RS-OTHER',
            'status' => ReorderSuggestion::STATUS_SUGGESTED,
            'run_date' => now()->toDateString(),
            'suggested_qty' => 5,
        ]);

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.accept', $suggestion), ['supplier_id' => $this->supplier()->id])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->post(route('inventory.reorder.suggestions.dismiss', $suggestion), ['note' => 'Not ours'])
            ->assertNotFound();
    }
}
