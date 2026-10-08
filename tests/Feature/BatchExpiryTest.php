<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\BatchService;
use App\Domain\Inventory\Services\StockLedgerService;
use App\Domain\Foundation\Company;
use App\Domain\Inventory\StockBatch;
use App\Domain\Inventory\StockBatchExpiryChange;
use App\Domain\Inventory\StockLayer;
use App\Domain\Inventory\StockMovement;
use App\Domain\Notification\Notification;
use App\Domain\Settings\Services\SettingService;
use App\Http\Requests\StoreGoodsReceiptRequest;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-37…04-41 — batches, expiry dates and FEFO.
 *
 * What this pins:
 *  · a batch hangs off the valuation layer, never a mirrored quantity — the
 *    register's figure is read from the layers, so it cannot drift from stock;
 *  · an inbound movement names its batch; a batch-tracked product MUST name one,
 *    because stock whose expiry can never be asked about is not tracked stock;
 *  · a later receipt only fills a blank date in — a date already recorded is a
 *    fact, and changing it is a correction with a reason and a trail;
 *  · an outbound movement never invents a batch: the layers it consumed already
 *    know where the stock came from;
 *  · a batch-tracked product is issued earliest-expiry-first (FEFO), and that
 *    rule is a setting, not a hard-coded opinion — switching it off falls back
 *    to the product's valuation method;
 *  · the expiry desk lists expired, expiring and undated stock with stock still
 *    on hand, and the alert digest is deduped to one per state per day.
 */
class BatchExpiryTest extends TestCase
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
        $request = Request::create('/__batch', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function product(string $sku, bool $trackBatch = false, string $method = 'fifo'): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Batch probe '.$sku,
            'cost_method' => $method,
            'standard_cost' => 10,
            'is_stocked' => true,
            'is_active' => true,
            'track_batch' => $trackBatch,
        ], $this->request());
    }

    protected function receive(
        Product $product,
        float $qty,
        float $cost = 10,
        ?string $batchNo = null,
        ?string $expiresOn = null,
        ?string $manufacturedOn = null,
    ): StockMovement {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockMovement::TYPE_PURCHASE_RECEIPT,
            'qty' => $qty,
            'unit_cost' => $cost,
            'batch_no' => $batchNo,
            'expires_on' => $expiresOn,
            'manufactured_on' => $manufacturedOn,
            'idempotency_key' => uniqid('batch-recv-', true),
        ], $this->admin);
    }

    /** Issue stock the way a sale or an adjustment would. */
    protected function issue(Product $product, float $qty, ?string $batchNo = null): StockMovement
    {
        return app(StockLedgerService::class)->post([
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'movement_type' => StockMovement::TYPE_ADJUST_OUT,
            'qty' => $qty,
            'batch_no' => $batchNo,
            'idempotency_key' => uniqid('batch-issue-', true),
        ], $this->admin);
    }

    protected function batch(string $batchNo): StockBatch
    {
        return StockBatch::query()->where('batch_no', $batchNo)->firstOrFail();
    }

    /** The register row, with the quantity and state the screen would show. */
    protected function registerRow(string $batchNo): StockBatch
    {
        return app(BatchService::class)->batches([], 30)->where('batch_no', $batchNo)->firstOrFail();
    }

    protected function days(int $offset): string
    {
        return now()->addDays($offset)->toDateString();
    }

    public function test_the_register_reads_its_quantity_from_the_valuation_layer(): void
    {
        $product = $this->product('BAT-1', trackBatch: true);

        $movement = $this->receive($product, 5, 40, 'LOT-A', $this->days(200));

        $batch = $this->batch('LOT-A');

        $this->assertSame($product->id, (int) $batch->product_id);
        $this->assertSame($this->warehouse->id, (int) $batch->warehouse_id);
        $this->assertSame($this->days(200), $batch->expires_on->toDateString());

        // The link is the layer, and the movement points at the same batch.
        $layer = StockLayer::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
        $this->assertSame($batch->id, (int) $layer->stock_batch_id);
        $this->assertSame($batch->id, (int) $movement->fresh()->stock_batch_id);

        $row = $this->registerRow('LOT-A');

        $this->assertSame(5.0, (float) $row->remaining_qty);
        $this->assertSame(200.0, (float) $row->remaining_value);
        $this->assertSame(StockBatch::STATE_OK, $row->state);
        $this->assertTrue($row->withStock()->where('batch_no', 'LOT-A')->exists(), 'the batch has stock to show');
    }

    public function test_a_later_receipt_fills_a_blank_date_but_never_overwrites_one(): void
    {
        $product = $this->product('BAT-2', trackBatch: true);

        $this->receive($product, 2, 10, 'LOT-B');

        $this->assertNull($this->batch('LOT-B')->expires_on);

        $this->receive($product, 2, 10, 'LOT-B', $this->days(90));

        $this->assertSame($this->days(90), $this->batch('LOT-B')->expires_on->toDateString());

        // A different date on a later delivery is a claim, not a fact: it is
        // ignored, because the way to move a date is the expiry desk.
        $this->receive($product, 2, 10, 'LOT-B', $this->days(400));

        $this->assertSame($this->days(90), $this->batch('LOT-B')->expires_on->toDateString());
        $this->assertSame(6.0, (float) $this->registerRow('LOT-B')->remaining_qty);

        // Filling a blank is not a correction, so no trail row was written.
        $this->assertSame(0, StockBatchExpiryChange::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'inventory.batch_dated')->count());
    }

    public function test_a_batch_tracked_product_must_name_its_batch(): void
    {
        $tracked = $this->product('BAT-3', trackBatch: true);

        try {
            $this->receive($tracked, 1, 5);
            $this->fail('A batch-tracked product accepted a movement with no batch.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('has to name the batch', $e->getMessage());
        }

        // Nothing was written: the refusal happens before any movement.
        $this->assertSame(0, StockMovement::query()->where('product_id', $tracked->id)->count());

        // A product that is not batch-tracked may still arrive without one.
        $plain = $this->product('BAT-4');
        $movement = $this->receive($plain, 1, 5);

        $this->assertNull($movement->stock_batch_id);
        $this->assertSame(0, StockBatch::query()->count());
    }

    public function test_an_outbound_movement_never_invents_a_batch(): void
    {
        $product = $this->product('BAT-5', trackBatch: true);
        $this->receive($product, 5, 10, 'LOT-E', $this->days(30));

        // An outbound command that names a batch is not believed: the layers it
        // consumed already know which batch the stock left from.
        $movement = $this->issue($product, 2, 'LOT-NEW');

        $this->assertNull($movement->stock_batch_id);
        $this->assertSame(1, StockBatch::query()->count());
        $this->assertSame(0, StockBatch::query()->where('batch_no', 'LOT-NEW')->count());
    }

    public function test_fefo_issues_the_earliest_expiring_batch_first(): void
    {
        $product = $this->product('BAT-6', trackBatch: true, method: 'fifo');

        // Received first, expires last — the exact case FIFO gets wrong.
        $this->receive($product, 5, 10, 'LOT-LATE', $this->days(300));
        $this->receive($product, 5, 10, 'LOT-SOON', $this->days(10));

        $this->issue($product, 3);

        $this->assertSame(5.0, (float) $this->registerRow('LOT-LATE')->remaining_qty);
        $this->assertSame(2.0, (float) $this->registerRow('LOT-SOON')->remaining_qty);
    }

    public function test_turning_fefo_off_falls_back_to_the_valuation_method(): void
    {
        app(SettingService::class)->set('inventory', 'fefo_picking', false, null, $this->admin);

        $product = $this->product('BAT-7', trackBatch: true, method: 'fifo');

        $this->receive($product, 5, 10, 'LOT-LATE2', $this->days(300));
        $this->receive($product, 5, 10, 'LOT-SOON2', $this->days(10));

        $this->issue($product, 3);

        // FIFO again: the oldest receipt goes out first, whatever its date says.
        $this->assertSame(2.0, (float) $this->registerRow('LOT-LATE2')->remaining_qty);
        $this->assertSame(5.0, (float) $this->registerRow('LOT-SOON2')->remaining_qty);
    }

    public function test_a_wrong_date_is_corrected_with_a_reason_and_the_trail_survives(): void
    {
        $product = $this->product('BAT-8', trackBatch: true);
        $this->receive($product, 4, 12, 'LOT-C', $this->days(60));

        $service = app(BatchService::class);
        $batch = $this->batch('LOT-C');
        $corrected = $this->days(120);

        try {
            $service->updateExpiry($batch, $corrected, '   ', $this->admin);
            $this->fail('A correction without a reason was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        try {
            $service->updateExpiry($batch, $this->days(60), 'same date', $this->admin);
            $this->fail('A no-op correction was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing to correct', $e->getMessage());
        }

        $this->assertSame(0, StockBatchExpiryChange::query()->count());

        $updated = $service->updateExpiry($batch, $corrected, 'Supplier confirmed the label was misread.', $this->admin);

        $this->assertSame($corrected, $updated->expires_on->toDateString());

        $change = StockBatchExpiryChange::query()->firstOrFail();
        $this->assertSame($this->days(60), $change->expires_on_before->toDateString());
        $this->assertSame($corrected, $change->expires_on_after->toDateString());
        $this->assertSame($this->admin->id, (int) $change->changed_by);
        $this->assertStringContainsString('misread', (string) $change->reason);

        $audit = AuditEvent::query()->where('action', 'inventory.batch_expiry_corrected')->firstOrFail();
        $this->assertSame($this->days(60), $audit->before['expires_on'] ?? null);
        $this->assertSame($corrected, $audit->after['expires_on'] ?? null);

        // Correcting a date never touches stock.
        $this->assertSame(4.0, (float) $this->registerRow('LOT-C')->remaining_qty);
    }

    public function test_the_expiry_desk_buckets_expired_expiring_and_undated_stock(): void
    {
        $product = $this->product('BAT-9', trackBatch: true);

        $this->receive($product, 2, 10, 'LOT-EXP', $this->days(-3));
        $this->receive($product, 2, 10, 'LOT-SOON3', $this->days(10));
        $this->receive($product, 2, 10, 'LOT-UND');
        $this->receive($product, 2, 10, 'LOT-OK', $this->days(300));

        // Fully consumed: an expired batch nobody has any of is history.
        $this->receive($product, 2, 10, 'LOT-GONE', $this->days(-5));
        $this->issue($product, 2);

        $buckets = app(BatchService::class)->buckets(30);

        $this->assertSame(1, $buckets['expired']['batches']);
        $this->assertSame('LOT-EXP', $buckets['expired']['rows']->first()->batch_no);
        $this->assertSame(20.0, $buckets['expired']['value']);

        $this->assertSame(1, $buckets['expiring']['batches']);
        $this->assertSame('LOT-SOON3', $buckets['expiring']['rows']->first()->batch_no);

        $this->assertSame(1, $buckets['undated']['batches']);
        $this->assertSame('LOT-UND', $buckets['undated']['rows']->first()->batch_no);

        // FEFO took the −5 day batch first when the issue ran.
        $this->assertSame(0.0, (float) $this->registerRow('LOT-GONE')->remaining_qty);

        // The register is ordered the way a storekeeper works it out: earliest
        // date first, and the batches nobody dated last.
        $this->assertSame(
            ['LOT-GONE', 'LOT-EXP', 'LOT-SOON3', 'LOT-OK', 'LOT-UND'],
            app(BatchService::class)->batches([], 30)->pluck('batch_no')->all(),
        );
    }

    public function test_the_register_filter_narrows_by_state_product_and_warehouse(): void
    {
        $product = $this->product('BAT-10', trackBatch: true);
        $other = $this->product('BAT-11', trackBatch: true);

        $this->receive($product, 2, 10, 'LOT-R1', $this->days(5));
        $this->receive($other, 2, 10, 'LOT-R2', $this->days(5));

        $service = app(BatchService::class);

        $this->assertSame(2, $service->batches(['state' => StockBatch::STATE_EXPIRING], 30)->count());
        $this->assertSame(1, $service->batches(['state' => StockBatch::STATE_EXPIRING, 'product_id' => $product->id], 30)->count());
        $this->assertSame(1, $service->batches(['q' => 'BAT-11'], 30)->count());
        $this->assertSame(0, $service->batches(['q' => 'nope'], 30)->count());
        $this->assertSame(2, $service->batches(['warehouse_id' => $this->warehouse->id], 30)->count());
        $this->assertSame(0, $service->batches(['warehouse_id' => 99999], 30)->count());
    }

    public function test_the_expiry_desk_lists_and_the_register_renders_over_http(): void
    {
        $product = $this->product('BAT-12', trackBatch: true);
        $this->receive($product, 3, 15, 'LOT-H', $this->days(-2));

        $this->actingAs($this->admin)
            ->get(route('inventory.batches.index'))
            ->assertOk()
            ->assertSee('LOT-H');

        $this->actingAs($this->admin)
            ->get(route('inventory.batches.expiry', ['type' => 'expired']))
            ->assertOk()
            ->assertSee('LOT-H');
    }

    public function test_reading_the_register_and_correcting_a_date_are_two_permissions(): void
    {
        $product = $this->product('BAT-13', trackBatch: true);
        $this->receive($product, 3, 15, 'LOT-P', $this->days(45));
        $batch = $this->batch('LOT-P');

        $reader = $this->makeUser();
        $reader->roles()->attach($this->roleWith([
            'portal.erp.access', 'dashboard.view', 'inventory.batch.view',
        ])->id);

        $this->actingAs($reader)->get(route('inventory.batches.index'))->assertOk();
        $this->actingAs($reader)->get(route('inventory.batches.expiry'))->assertOk();

        // Reading a date is not permission to move it.
        $this->actingAs($reader)
            ->post(route('inventory.batches.expiry.update', $batch), [
                'expires_on' => $this->days(50),
                'reason' => 'Reader tried to move a date.',
            ])
            ->assertForbidden();

        $this->assertSame($this->days(45), $this->batch('LOT-P')->expires_on->toDateString());

        $stranger = $this->makeUser();
        $stranger->roles()->attach($this->roleWith(['portal.erp.access', 'dashboard.view'])->id);

        $this->actingAs($stranger)->get(route('inventory.batches.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('inventory.batches.expiry'))->assertForbidden();
    }

    public function test_a_batch_from_another_company_is_a_404_not_an_edit(): void
    {
        $product = $this->product('BAT-14', trackBatch: true);

        // A real second company, not an invented id: `stock_batches.company_id`
        // is a foreign key, so an id nothing owns cannot even be inserted — and
        // a fixture that cannot be written cannot prove that isolation works.
        // `singleton` is not mass-assignable on purpose (the column is what
        // holds the one-company-at-a-time rule), so it is set by hand.
        $elsewhere = new Company(['name' => 'Other Traders Ltd', 'is_active' => true]);
        $elsewhere->singleton = false;
        $elsewhere->save();

        $foreign = StockBatch::query()->create([
            'company_id' => $elsewhere->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'batch_no' => 'FOREIGN-1',
            'expires_on' => $this->days(20),
        ]);

        $this->actingAs($this->admin)
            ->post(route('inventory.batches.expiry.update', $foreign), [
                'expires_on' => $this->days(30),
                'reason' => 'Not my batch.',
            ])
            ->assertNotFound();

        $this->assertSame($this->days(20), $foreign->fresh()->expires_on->toDateString());
        $this->assertSame(0, StockBatchExpiryChange::query()->count());
    }

    public function test_a_correction_over_http_keeps_the_reason_and_returns_to_the_desk(): void
    {
        $product = $this->product('BAT-15', trackBatch: true);
        $this->receive($product, 3, 15, 'LOT-RQ', $this->days(15));
        $batch = $this->batch('LOT-RQ');

        $this->actingAs($this->admin)
            ->from(route('inventory.batches.expiry', ['type' => 'expiring']))
            ->post(route('inventory.batches.expiry.update', $batch), [
                'expires_on' => $this->days(80),
                'reason' => 'The box said 12/2026 and the clerk typed 10/2026.',
            ])
            ->assertRedirect(route('inventory.batches.expiry', ['type' => 'expiring']))
            ->assertSessionHas('status');

        $this->assertSame($this->days(80), $this->batch('LOT-RQ')->expires_on->toDateString());

        // A blank reason is refused with the form's own message, not a 500.
        $this->actingAs($this->admin)
            ->post(route('inventory.batches.expiry.update', $batch), ['expires_on' => $this->days(90)])
            ->assertSessionHasErrors('reason');

        // The same date as it already carries is refused in-domain too.
        $this->actingAs($this->admin)
            ->post(route('inventory.batches.expiry.update', $batch), [
                'expires_on' => $this->days(80),
                'reason' => 'No change at all.',
            ])
            ->assertSessionHasErrors('expires_on');
    }

    public function test_the_expiry_digest_reaches_the_desk_once_a_day(): void
    {
        $product = $this->product('BAT-16', trackBatch: true);
        $this->receive($product, 3, 20, 'LOT-N1', $this->days(-1));
        $this->receive($product, 3, 20, 'LOT-N2', $this->days(7));

        $this->artisan('erp:inventory:expiry-alerts', ['--company' => $this->admin->company_id])
            ->assertSuccessful();

        $expired = Notification::query()
            ->where('user_id', $this->admin->id)
            ->where('event_type', 'inventory.batch.expired')
            ->get();

        $this->assertCount(1, $expired);
        $this->assertStringContainsString('past their expiry date', (string) $expired->first()->title);
        $this->assertSame('/app/inventory/expiry?type=expired&days=30', $expired->first()->action_url);
        $this->assertSame('high', $expired->first()->priority);
        $this->assertStringContainsString('LOT-N1', (string) $expired->first()->body);

        $this->assertSame(1, Notification::query()
            ->where('event_type', 'inventory.batch.expiring')
            ->where('user_id', $this->admin->id)
            ->count());

        // Running it again the same day must not send the same digest twice.
        $this->artisan('erp:inventory:expiry-alerts', ['--company' => $this->admin->company_id])
            ->assertSuccessful();

        $this->assertSame(1, Notification::query()
            ->where('event_type', 'inventory.batch.expired')
            ->where('user_id', $this->admin->id)
            ->count());

        // ...and an undated batch is nobody's alert yet: nothing holds it back
        // from being dated, but there is no stock in the undated bucket here
        // beyond what the two receipts above account for.
        $this->assertSame(0, Notification::query()
            ->where('event_type', 'inventory.batch.undated')
            ->count());
    }

    public function test_the_expiry_desk_hands_an_expired_batch_to_the_write_off_document(): void
    {
        $product = $this->product('BAT-17', trackBatch: true);
        $this->receive($product, 6, 25, 'LOT-W', $this->days(-4));

        $this->actingAs($this->admin)
            ->get(route('inventory.batches.expiry', ['type' => 'expired']))
            ->assertOk()
            ->assertSee('Write off this batch');

        // The hand-over is a link into the write-off document that already
        // exists: the disposal path is not re-implemented on the expiry desk.
        $this->actingAs($this->admin)
            ->get(route('inventory.writeoffs.create', [
                'warehouse' => $this->warehouse->id,
                'product' => $product->id,
                'qty' => 6,
                'source' => 'on_hand',
                'reason' => 'Expired batch LOT-W',
            ]))
            ->assertOk()
            ->assertSee('Expired batch LOT-W');
    }

    public function test_the_receipt_request_refuses_an_expiry_before_the_manufactured_date(): void
    {
        $request = StoreGoodsReceiptRequest::create('/app/purchase/receipts', 'POST', [
            'lines' => [[
                'product_id' => 1,
                'qty_received' => 1,
                'manufactured_on' => '2026-03-10',
                'expires_on' => '2026-01-05',
            ]],
        ]);

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->errors()->has('lines.0.expires_on'));

        // An expiry date on its own is the normal case and must pass.
        $solo = StoreGoodsReceiptRequest::create('/app/purchase/receipts', 'POST', [
            'lines' => [[
                'product_id' => 1,
                'qty_received' => 1,
                'manufactured_on' => null,
                'expires_on' => '2030-01-05',
            ]],
        ]);

        $this->assertFalse(
            Validator::make($solo->all(), $solo->rules())->errors()->has('lines.0.expires_on'),
        );
    }
}
