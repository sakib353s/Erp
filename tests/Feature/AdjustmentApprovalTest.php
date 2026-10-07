<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Actions\PostStockAdjustment;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockAdjustment;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Settings\Services\SettingService;
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
 * §04-26 — adjustment approval and history.
 *
 * What this pins:
 *  · an adjustment below the threshold posts exactly as it always did;
 *  · one at or above it is stored and touches nothing, because a pending
 *    document with movements behind it would not be pending at all;
 *  · the value the threshold judged is kept on the document, so changing the
 *    setting never rewrites history;
 *  · maker ≠ checker, on approval and on rejection alike;
 *  · approving writes the movements once, and only once;
 *  · rejecting moves nothing and demands a reason.
 */
class AdjustmentApprovalTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected User $approver;

    protected Branch $branch;

    protected Warehouse $warehouse;

    protected Product $product;

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

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'ADJ-SKU',
            'sku' => 'ADJ-SKU',
            'name' => 'Adjustment probe',
            'cost_method' => 'wac',
            'standard_cost' => 50,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 10, 'unit_cost' => 50]],
            'idempotency_suffix' => 'adj-open',
        ], $this->request());

        $this->approver = $this->makeUser(['name' => 'Second Person']);
        $this->approver->roles()->attach($this->roleWith([
            'portal.erp.access',
            'inventory.adjustments.view',
            'inventory.adjustments.create',
            'inventory.adjustments.approve',
        ])->id);
    }

    protected function request(): Request
    {
        $request = Request::create('/__adjustments', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function action(): PostStockAdjustment
    {
        return app(PostStockAdjustment::class);
    }

    /** @param array<int, array<string, mixed>> $lines */
    protected function payload(array $lines): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'adjustment_date' => now()->toDateString(),
            'reason' => 'Shelf disagreed with the ledger',
            'lines' => $lines,
        ];
    }

    protected function threshold(float $value): void
    {
        app(SettingService::class)->set('inventory', 'adjustment_approval_above', $value, null, $this->admin);
    }

    protected function ledgerMovements(): int
    {
        return StockMovement::query()->where('source_type', 'stock_adjustment')->count();
    }

    protected function onHand(): float
    {
        return (float) (StockBalance::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand') ?? 0);
    }

    public function test_an_adjustment_below_the_threshold_posts_immediately_and_records_its_value(): void
    {
        $this->threshold(1000);

        $adjustment = $this->action()->submit($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -1],
        ]), $this->admin);

        $this->assertTrue($adjustment->isPosted());
        $this->assertSame(50.0, (float) $adjustment->total_value, 'the value the threshold judged is kept');
        $this->assertNotNull($adjustment->posted_at);
        $this->assertSame(1, $this->ledgerMovements());
        $this->assertSame(9.0, $this->onHand());
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.adjustment_posted']);
    }

    public function test_an_adjustment_at_or_above_the_threshold_waits_and_moves_no_stock(): void
    {
        $this->threshold(100);

        // Exactly at the threshold: "at least this much" is the rule, not "more".
        $adjustment = $this->action()->submit($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -2],
        ]), $this->admin);

        $this->assertTrue($adjustment->isPending());
        $this->assertSame(100.0, (float) $adjustment->total_value);
        $this->assertNull($adjustment->posted_at);
        $this->assertSame(0, $this->ledgerMovements(), 'a pending adjustment has no movements behind it');
        $this->assertSame(10.0, $this->onHand(), 'nothing moved');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.adjustment_submitted']);

        // And the line carries the cost the document was judged at.
        $this->assertSame(50.0, (float) $adjustment->lines->first()->unit_cost);
    }

    public function test_with_no_threshold_every_adjustment_still_posts_directly(): void
    {
        // The default is 0 — nothing that existed before this slice changes shape.
        $this->assertSame(0.0, $this->action()->approvalThreshold());

        $adjustment = $this->action()->submit($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -5],
        ]), $this->admin);

        $this->assertTrue($adjustment->isPosted());
        $this->assertSame(1, $this->ledgerMovements());
        $this->assertSame(5.0, $this->onHand());
    }

    public function test_the_person_who_raised_it_cannot_decide_their_own_document(): void
    {
        $this->threshold(100);

        $adjustment = $this->action()->submit($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -2],
        ]), $this->admin);

        try {
            $this->action()->approve($adjustment, $this->admin);
            $this->fail('Self-approval must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be approved or rejected by the person who raised it', $e->getMessage());
        }

        try {
            $this->action()->reject($adjustment, $this->admin, 'Not on my watch');
            $this->fail('Self-rejection must be refused too.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('person who raised it', $e->getMessage());
        }

        $this->assertTrue($adjustment->refresh()->isPending());
        $this->assertSame(0, $this->ledgerMovements());
    }

    public function test_approval_posts_the_movements_once_and_closes_the_document(): void
    {
        $this->threshold(100);

        $adjustment = $this->action()->submit($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -2],
        ]), $this->admin);

        $approved = $this->action()->approve($adjustment, $this->approver, 'Counted it myself');

        $this->assertTrue($approved->isPosted());
        $this->assertSame($this->approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame('Counted it myself', $approved->approval_note);
        $this->assertSame(1, $this->ledgerMovements());
        $this->assertSame(8.0, $this->onHand(), 'the stock moved at approval, and by exactly the counted amount');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.adjustment_approved']);

        try {
            $this->action()->approve($approved, $this->approver);
            $this->fail('A decided adjustment must not be decidable again.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('waiting for approval', $e->getMessage());
        }

        $this->assertSame(1, $this->ledgerMovements(), 'still one movement — approval cannot double-post');
    }

    public function test_rejection_needs_a_reason_and_changes_nothing_but_the_status(): void
    {
        $this->threshold(100);

        $adjustment = $this->action()->submit($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -3],
        ]), $this->admin);

        try {
            $this->action()->reject($adjustment, $this->approver, '   ');
            $this->fail('A rejection without a reason must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        $rejected = $this->action()->reject($adjustment, $this->approver, 'The count sheet is wrong, recount it');

        $this->assertSame(StockAdjustment::STATUS_REJECTED, $rejected->status);
        $this->assertSame($this->approver->id, $rejected->approved_by);
        $this->assertSame(0, $this->ledgerMovements());
        $this->assertSame(10.0, $this->onHand(), 'a refused adjustment leaves the shelf exactly as it was');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.adjustment_rejected']);
    }

    public function test_the_register_and_its_history_carry_the_decision_over_http(): void
    {
        $this->threshold(100);

        // Someone who may see the register but not decide it.
        $reader = $this->makeUser(['name' => 'Register Reader']);
        $reader->roles()->attach($this->roleWith([
            'portal.erp.access',
            'inventory.adjustments.view',
        ])->id);

        $this->actingAs($this->admin)
            ->post(route('inventory.adjustments.store'), [
                'warehouse_id' => $this->warehouse->id,
                'adjustment_date' => now()->toDateString(),
                'reason' => 'Posted from the form',
                'lines' => [['product_id' => $this->product->id, 'qty_delta' => -4]],
            ])
            ->assertRedirect(route('inventory.adjustments.index', ['status' => StockAdjustment::STATUS_PENDING]));

        $adjustment = StockAdjustment::query()->latest('id')->firstOrFail();
        $this->assertTrue($adjustment->isPending());

        $this->actingAs($reader)->get(route('inventory.adjustments.index'))->assertOk()->assertSee($adjustment->adjustment_no);
        $this->actingAs($reader)->get(route('inventory.adjustments.history'))->assertOk();
        $this->actingAs($reader)
            ->post(route('inventory.adjustments.approve', $adjustment))
            ->assertForbidden();

        $this->assertTrue($adjustment->refresh()->isPending());

        $this->actingAs($this->approver)
            ->post(route('inventory.adjustments.approve', $adjustment), ['note' => 'Checked against the shelf'])
            ->assertRedirect();

        $adjustment->refresh();

        $this->assertTrue($adjustment->isPosted());
        $this->assertSame(6.0, $this->onHand());

        $this->actingAs($this->approver)
            ->get(route('inventory.adjustments.history'))
            ->assertOk()
            ->assertSee('Checked against the shelf', false);
    }

    public function test_a_posted_count_is_not_put_through_the_approval_gate(): void
    {
        $this->threshold(1);

        // §04-31 posts a count through the direct path: the count already had a
        // counter and a poster, so a third decision would be theatre.
        $adjustment = $this->action()->post($this->payload([
            ['product_id' => $this->product->id, 'qty_delta' => -6],
        ]), $this->admin);

        $this->assertTrue($adjustment->isPosted());
        $this->assertSame(1, $this->ledgerMovements());
        $this->assertSame(4.0, $this->onHand());

        // And it is recorded as posted, not as waiting for somebody's opinion.
        $this->assertDatabaseHas('stock_adjustments', [
            'id' => $adjustment->id,
            'status' => StockAdjustment::STATUS_POSTED,
        ]);
        $this->assertDatabaseMissing('audit_events', ['action' => 'inventory.adjustment_submitted']);
    }
}
