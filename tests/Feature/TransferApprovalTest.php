<?php

namespace Tests\Feature;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Actions\StockTransferService;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockTransfer;
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
 * §04-28 — transfer approval.
 *
 * What this pins:
 *  · a transfer below the threshold is a draft, exactly as it always was;
 *  · one at or above it waits, and a waiting transfer **cannot be dispatched** —
 *    walking to the next step does not walk around the gate;
 *  · approval turns it into a draft and nothing more: the stock moves on
 *    dispatch, so "in transit" always means somebody approved it;
 *  · maker ≠ checker, on approval and rejection alike;
 *  · rejection ends the document — it can never be dispatched;
 *  · the value the threshold judged is kept on the document.
 */
class TransferApprovalTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected User $approver;

    protected Branch $branch;

    protected Warehouse $source;

    protected Warehouse $destination;

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

        $this->source = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->destination = app(\App\Domain\Inventory\Services\WarehouseService::class)->createWarehouse([
            'branch_id' => $this->branch->id,
            'code' => 'DEPOT',
            'name' => 'Depot',
        ], $this->admin);

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'TRF-SKU',
            'sku' => 'TRF-SKU',
            'name' => 'Transfer probe',
            'cost_method' => 'wac',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->source->id,
            'lines' => [['product_id' => $this->product->id, 'qty' => 20, 'unit_cost' => 100]],
            'idempotency_suffix' => 'trf-open',
        ], $this->request());

        $this->approver = $this->makeUser(['name' => 'Desk Approver']);
        $this->approver->roles()->attach($this->roleWith([
            'portal.erp.access',
            'inventory.transfers.create',
            'inventory.transfers.dispatch',
            'inventory.transfers.approve',
        ])->id);
    }

    protected function request(): Request
    {
        $request = Request::create('/__transfers', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function service(): StockTransferService
    {
        return app(StockTransferService::class);
    }

    protected function threshold(float $value): void
    {
        app(SettingService::class)->set('inventory', 'transfer_approval_above', $value, null, $this->admin);
    }

    /** @param array<int, array<string, mixed>>|null $lines */
    protected function payload(?array $lines = null, ?float $cost = null): array
    {
        return [
            'from_warehouse_id' => $this->source->id,
            'to_warehouse_id' => $this->destination->id,
            'transfer_date' => now()->toDateString(),
            'narration' => 'Moving to the depot',
            'lines' => $lines ?? [[
                'product_id' => $this->product->id,
                'qty_sent' => 5,
                'unit_cost' => $cost,
            ]],
        ];
    }

    protected function onHand(Warehouse $warehouse): float
    {
        return (float) (StockBalance::query()
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('on_hand') ?? 0);
    }

    protected function transferMovements(): int
    {
        return StockMovement::query()->where('source_type', 'stock_transfer')->count();
    }

    public function test_a_transfer_below_the_threshold_is_a_draft_as_before(): void
    {
        $this->threshold(1000);

        $transfer = $this->service()->create($this->payload(), $this->request());

        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->status);
        $this->assertTrue($transfer->isDispatchable());
        $this->assertSame(500.0, (float) $transfer->total_value, '5 × 100 judged by the ledger cost');
        $this->assertSame(0, $this->transferMovements(), 'a draft has moved nothing');
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.transfer_created']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'inventory.transfer_submitted']);
    }

    public function test_with_no_threshold_nothing_changes(): void
    {
        $this->assertSame(0.0, $this->service()->approvalThreshold());

        $transfer = $this->service()->create($this->payload(), $this->request());

        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->status);
    }

    public function test_a_transfer_at_or_above_the_threshold_waits_and_cannot_be_dispatched(): void
    {
        $this->threshold(400);

        $transfer = $this->service()->create($this->payload(), $this->request());

        $this->assertTrue($transfer->isPending(), 'at the threshold counts as over it');
        $this->assertSame(500.0, (float) $transfer->total_value);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.transfer_submitted']);

        // The gate is not a label: the next step refuses to run.
        try {
            $this->service()->dispatch($transfer->refresh(), $this->request());
            $this->fail('A transfer waiting for approval must not be dispatchable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('still waiting for approval', $e->getMessage());
        }

        $this->assertSame(StockTransfer::STATUS_PENDING, $transfer->refresh()->status);
        $this->assertSame(20.0, $this->onHand($this->source), 'nothing left the origin');
        $this->assertSame(0, $this->transferMovements());
    }

    public function test_the_person_who_raised_it_cannot_decide_their_own_transfer(): void
    {
        $this->threshold(100);

        $transfer = $this->service()->create($this->payload(), $this->request());

        try {
            $this->service()->approve($transfer, $this->admin);
            $this->fail('Self-approval must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be approved or rejected by the person who raised it', $e->getMessage());
        }

        try {
            $this->service()->reject($transfer, $this->admin, 'No');
            $this->fail('Self-rejection must be refused too.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('person who raised it', $e->getMessage());
        }

        $this->assertTrue($transfer->refresh()->isPending());
    }

    public function test_approval_only_opens_the_gate_and_the_dispatch_then_moves_the_stock(): void
    {
        $this->threshold(100);

        $transfer = $this->service()->create($this->payload(), $this->request());
        $approved = $this->service()->approve($transfer, $this->approver, 'Stock is going for a reason');

        $this->assertSame(StockTransfer::STATUS_DRAFT, $approved->status, 'approval makes it dispatchable, not dispatched');
        $this->assertSame($this->approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame('Stock is going for a reason', $approved->approval_note);
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.transfer_approved']);
        $this->assertSame(0, $this->transferMovements());
        $this->assertSame(20.0, $this->onHand($this->source));

        // Now the two-leg lifecycle runs exactly as it always did.
        $dispatched = $this->service()->dispatch($approved->refresh(), $this->request());

        $this->assertSame(StockTransfer::STATUS_DISPATCHED, $dispatched->status);
        $this->assertSame(15.0, $this->onHand($this->source), 'the goods left the origin');
        $this->assertSame(1, $this->transferMovements());

        try {
            $this->service()->approve($dispatched->refresh(), $this->approver);
            $this->fail('A decided transfer must not be decidable again.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('waiting for approval', $e->getMessage());
        }
    }

    public function test_rejection_needs_a_reason_and_ends_the_transfer(): void
    {
        $this->threshold(100);

        $transfer = $this->service()->create($this->payload(), $this->request());

        try {
            $this->service()->reject($transfer, $this->approver, '  ');
            $this->fail('A rejection without a reason must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        $rejected = $this->service()->reject($transfer, $this->approver, 'Send it to the other depot');

        $this->assertSame(StockTransfer::STATUS_REJECTED, $rejected->status);
        $this->assertSame($this->approver->id, $rejected->approved_by);
        $this->assertSame(20.0, $this->onHand($this->source));
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.transfer_rejected']);

        // A refused transfer is finished: it cannot be dispatched later.
        try {
            $this->service()->dispatch($rejected->refresh(), $this->request());
            $this->fail('A rejected transfer must never be dispatchable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only draft transfers', $e->getMessage());
        }

        $this->assertSame(0, $this->transferMovements());
    }

    public function test_the_register_carries_the_decision_over_http(): void
    {
        $this->threshold(100);

        $reader = $this->makeUser(['name' => 'Warehouse Viewer']);
        $reader->roles()->attach($this->roleWith([
            'portal.erp.access',
            'inventory.transfers.create',
        ])->id);

        $this->actingAs($this->admin)
            ->post(route('inventory.transfers.store'), [
                'from_warehouse_id' => $this->source->id,
                'to_warehouse_id' => $this->destination->id,
                'transfer_date' => now()->toDateString(),
                'narration' => 'Posted from the form',
                'lines' => [['product_id' => $this->product->id, 'qty_sent' => 6, 'unit_cost' => 100]],
            ])
            ->assertRedirect(route('inventory.transfers.index', ['status' => StockTransfer::STATUS_PENDING]));

        $transfer = StockTransfer::query()->latest('id')->firstOrFail();
        $this->assertTrue($transfer->isPending());

        $this->actingAs($reader)->get(route('inventory.transfers.index'))->assertOk()->assertSee($transfer->transfer_no);
        $this->actingAs($reader)
            ->post(route('inventory.transfers.approve', $transfer))
            ->assertForbidden();

        $this->assertTrue($transfer->refresh()->isPending());

        $this->actingAs($this->approver)
            ->post(route('inventory.transfers.approve', $transfer), ['note' => 'Checked with the depot'])
            ->assertRedirect();

        $transfer->refresh();

        $this->assertSame(StockTransfer::STATUS_DRAFT, $transfer->status);
        $this->actingAs($this->approver)->get(route('inventory.transfers.index'))->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory.transfer_approved']);
    }
}
