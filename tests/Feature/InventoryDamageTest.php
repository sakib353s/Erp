<?php

namespace Tests\Feature;

use App\Domain\Accounting\JournalEntry;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\DamageService;
use App\Domain\Inventory\Services\ValuationService;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockDamageEntry;
use App\Domain\Inventory\StockMovement;
use App\Domain\Inventory\StockWriteoff;
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
 * §04-46…04-49, 04-51 — damage, loss, and the write-off that removes value.
 *
 * What this pins:
 *  · damage moves goods out of sellable stock into the damaged compartment and
 *    changes their value not at all — they are still ours, just unsellable;
 *  · a loss consumes the valuation layers and books the cost, because the goods
 *    are gone and pretending otherwise would overstate the balance sheet;
 *  · a write-off moves nothing until a second person approves it, and a
 *    rejection moves nothing at all;
 *  · the ledger refuses to flag, lose or write off stock that is not there,
 *    loudly, instead of flooring a balance at zero;
 *  · the report adds up to the documents that exist, and a compartment is what
 *    the balance row says it is.
 */
class InventoryDamageTest extends TestCase
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
        $request = Request::create('/__damage', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function damage(): DamageService
    {
        return app(DamageService::class);
    }

    protected function stocked(string $sku, float $qty, float $cost = 100): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Damage probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => $cost,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->request());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => $cost]],
            'idempotency_suffix' => uniqid('dmg-', true),
        ], $this->request());

        return $product;
    }

    protected function balance(Product $product): StockBalance
    {
        return StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    /** The debit and credit account codes of a journal entry, for assertions. */
    protected function entrySides(JournalEntry $entry): array
    {
        $codes = [];

        foreach ($entry->lines()->with('account')->get() as $line) {
            $codes[$line->dc] = $line->account->code;
        }

        return $codes;
    }

    public function test_recording_damage_moves_goods_out_of_sellable_stock_and_keeps_their_value(): void
    {
        $product = $this->stocked('DMG-1', 10);

        $entry = $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason_code' => 'handling',
            'reason' => 'Forklift clipped the pallet.',
            'lines' => [['product_id' => $product->id, 'qty' => 4]],
        ], $this->admin);

        $balance = $this->balance($product);

        $this->assertSame('6.0000', (string) $balance->on_hand, 'sellable stock drops');
        $this->assertSame('4.0000', (string) $balance->damaged, 'and is held as damaged');
        $this->assertSame(6.0, $balance->available(), 'damaged goods are not available to sell');

        // The goods are still ours at the same cost: no layer was touched.
        $this->assertSame(1000.0, app(ValuationService::class)->stockValue($product, $this->warehouse));
        $this->assertSame('400.0000', (string) $entry->total_value);
        $this->assertSame(StockDamageEntry::STATUS_RECORDED, $entry->status);
        $this->assertNull($entry->journal_entry_id, 'nothing left the company, so nothing is posted');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_DAMAGE_IN,
            'state' => StockMovement::STATE_DAMAGED,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'inventory.damage_recorded',
            'entity_id' => $entry->id,
        ]);
    }

    public function test_a_damage_entry_can_be_released_back_into_sellable_stock(): void
    {
        $product = $this->stocked('DMG-2', 10);

        $entry = $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason' => 'Suspected water damage.',
            'lines' => [['product_id' => $product->id, 'qty' => 2]],
        ], $this->admin);

        $this->damage()->releaseDamage($entry, $this->admin);

        $balance = $this->balance($product);

        $this->assertSame('10.0000', (string) $balance->on_hand);
        $this->assertSame('0.0000', (string) $balance->damaged);
        $this->assertSame(StockDamageEntry::STATUS_RELEASED, $entry->refresh()->status);
        $this->assertSame(1000.0, app(ValuationService::class)->stockValue($product, $this->warehouse));

        // Releasing twice is refused rather than silently moving stock again.
        $this->expectException(RuntimeException::class);
        $this->damage()->releaseDamage($entry->refresh(), $this->admin);
    }

    public function test_a_loss_consumes_the_layers_and_books_the_cost(): void
    {
        $product = $this->stocked('LOSS-1', 10);

        $entry = $this->damage()->recordEntry(StockDamageEntry::KIND_LOSS, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason_code' => 'theft',
            'reason' => 'Short at the night count.',
            'lines' => [['product_id' => $product->id, 'qty' => 3]],
        ], $this->admin);

        $balance = $this->balance($product);

        $this->assertSame('7.0000', (string) $balance->on_hand);
        $this->assertSame('0.0000', (string) $balance->damaged);
        $this->assertSame(700.0, app(ValuationService::class)->stockValue($product, $this->warehouse), 'the layers went with the goods');
        $this->assertSame('300.0000', (string) $entry->total_value);

        $journal = JournalEntry::query()->whereKey($entry->journal_entry_id)->firstOrFail();
        $sides = $this->entrySides($journal);

        $this->assertSame('5260', $sides['debit'], 'Dr Inventory Loss & Damage');
        $this->assertSame('1140', $sides['credit'], 'Cr Inventory');
        $this->assertSame('300.0000', (string) $journal->total_debit);

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_WRITE_OFF,
        ]);
    }

    public function test_a_writeoff_moves_nothing_until_a_second_person_approves_it(): void
    {
        $product = $this->stocked('WO-1', 10);

        $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason' => 'Crushed in storage.',
            'lines' => [['product_id' => $product->id, 'qty' => 4]],
        ], $this->admin);

        $writeoff = $this->damage()->raiseWriteoff([
            'warehouse_id' => $this->warehouse->id,
            'writeoff_date' => now()->toDateString(),
            'source_state' => StockMovement::STATE_DAMAGED,
            'reason' => 'Disposed of after inspection.',
            'lines' => [['product_id' => $product->id, 'qty' => 4]],
        ], $this->admin);

        $this->assertSame(StockWriteoff::STATUS_PENDING, $writeoff->status);
        $this->assertSame('400.0000', (string) $writeoff->total_value, 'valued from the layers at raise time');

        // Raised, and nothing has moved: the document is a request, not an act.
        $balance = $this->balance($product);
        $this->assertSame('6.0000', (string) $balance->on_hand);
        $this->assertSame('4.0000', (string) $balance->damaged);
        $this->assertSame(1000.0, app(ValuationService::class)->stockValue($product, $this->warehouse));

        // The person who raised it cannot be the second pair of eyes.
        try {
            $this->damage()->approveWriteoff($writeoff, $this->admin);
            $this->fail('A self-approved write-off must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('second person', $e->getMessage());
        }

        $approver = $this->makeUser(['name' => 'Store Manager']);
        $approved = $this->damage()->approveWriteoff($writeoff->refresh(), $approver);

        $balance = $this->balance($product);

        $this->assertSame(StockWriteoff::STATUS_APPROVED, $approved->status);
        $this->assertSame('6.0000', (string) $balance->on_hand, 'sellable stock was already reduced when the goods were flagged');
        $this->assertSame('0.0000', (string) $balance->damaged, 'the damaged compartment is now empty');
        $this->assertSame(600.0, app(ValuationService::class)->stockValue($product, $this->warehouse), 'the cost of 4 units left the layers');
        $this->assertSame('400.0000', (string) $approved->total_value);

        $journal = JournalEntry::query()->whereKey($approved->journal_entry_id)->firstOrFail();
        $sides = $this->entrySides($journal);

        $this->assertSame('5260', $sides['debit']);
        $this->assertSame('1140', $sides['credit']);
        $this->assertSame('400.0000', (string) $journal->total_debit);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'inventory.writeoff_approved',
            'entity_id' => $approved->id,
        ]);
    }

    public function test_a_rejected_writeoff_leaves_everything_where_it_is(): void
    {
        $product = $this->stocked('WO-2', 5);

        $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason' => 'Dented tins.',
            'lines' => [['product_id' => $product->id, 'qty' => 2]],
        ], $this->admin);

        $writeoff = $this->damage()->raiseWriteoff([
            'warehouse_id' => $this->warehouse->id,
            'writeoff_date' => now()->toDateString(),
            'source_state' => StockMovement::STATE_DAMAGED,
            'reason' => 'Not worth reconditioning.',
            'lines' => [['product_id' => $product->id, 'qty' => 2]],
        ], $this->admin);

        $approver = $this->makeUser(['name' => 'Store Manager']);
        $rejected = $this->damage()->rejectWriteoff($writeoff, 'Ask the supplier for a credit note first.', $approver);

        $this->assertSame(StockWriteoff::STATUS_REJECTED, $rejected->status);
        $this->assertSame('Ask the supplier for a credit note first.', $rejected->decision_note);

        $balance = $this->balance($product);
        $this->assertSame('3.0000', (string) $balance->on_hand);
        $this->assertSame('2.0000', (string) $balance->damaged);
        $this->assertSame(500.0, app(ValuationService::class)->stockValue($product, $this->warehouse));

        $this->assertSame(0, StockMovement::query()
            ->whereIn('movement_type', [StockMovement::TYPE_DAMAGE_OUT, StockMovement::TYPE_WRITE_OFF])
            ->count(), 'a rejection writes no movement at all');

        // A reason is not optional: a decision nobody can read is not a decision.
        $this->expectException(RuntimeException::class);
        $this->damage()->rejectWriteoff($writeoff->refresh(), '   ', $approver);
    }

    public function test_the_ledger_refuses_to_move_stock_that_is_not_there(): void
    {
        $product = $this->stocked('EDGE-1', 3);

        try {
            $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
                'warehouse_id' => $this->warehouse->id,
                'entry_date' => now()->toDateString(),
                'reason' => 'More than we hold.',
                'lines' => [['product_id' => $product->id, 'qty' => 99]],
            ], $this->admin);
            $this->fail('Flagging more stock as damaged than exists must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Not enough sellable stock', $e->getMessage());
        }

        try {
            $this->damage()->recordEntry(StockDamageEntry::KIND_LOSS, [
                'warehouse_id' => $this->warehouse->id,
                'entry_date' => now()->toDateString(),
                'reason' => 'More than we hold.',
                'lines' => [['product_id' => $product->id, 'qty' => 99]],
            ], $this->admin);
            $this->fail('A loss larger than stock on hand must be refused.');
        } catch (RuntimeException $e) {
            // Refused by the domain in plain words, before valuation layers get
            // a chance to say the same thing in accounting language.
            $this->assertStringContainsString('record a loss', $e->getMessage());
        }

        try {
            $this->damage()->raiseWriteoff([
                'warehouse_id' => $this->warehouse->id,
                'writeoff_date' => now()->toDateString(),
                'source_state' => StockMovement::STATE_DAMAGED,
                'reason' => 'Nothing was ever flagged as damaged.',
                'lines' => [['product_id' => $product->id, 'qty' => 1]],
            ], $this->admin);
            $this->fail('Writing off stock that was never flagged as damaged must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('more than exists', $e->getMessage());
        }

        $balance = $this->balance($product);
        $this->assertSame('3.0000', (string) $balance->on_hand, 'a refused entry moves nothing');
        $this->assertSame('0.0000', (string) $balance->damaged);

        // A refusal leaves no half-written document behind either.
        $this->assertSame(0, StockDamageEntry::query()->count());
        $this->assertSame(0, StockWriteoff::query()->count());
        $this->assertSame(0, StockMovement::query()->whereIn('movement_type', [
            StockMovement::TYPE_DAMAGE_IN, StockMovement::TYPE_WRITE_OFF,
        ])->count());
    }

    public function test_the_registers_the_report_and_the_permission_gates(): void
    {
        $product = $this->stocked('HTTP-1', 8);

        $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason_code' => 'storage',
            'reason' => 'Shelf collapsed.',
            'lines' => [['product_id' => $product->id, 'qty' => 3]],
        ], $this->admin);

        $this->damage()->recordEntry(StockDamageEntry::KIND_LOSS, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason_code' => 'miscount',
            'reason' => 'Count corrected.',
            'lines' => [['product_id' => $product->id, 'qty' => 1]],
        ], $this->admin);

        $this->actingAs($this->admin)->get('/app/inventory/damage')->assertOk()->assertSee('Shelf collapsed.');
        $this->actingAs($this->admin)->get('/app/inventory/loss')->assertOk()->assertSee('Count corrected.');

        $report = $this->actingAs($this->admin)->get('/app/reports/inventory/damage');
        $report->assertOk();
        $report->assertSee('Recorded value');
        $report->assertSee('400.00');   // 300 damage + 100 loss recorded in the window
        $report->assertSee('300.00');   // damage only
        $report->assertSee('100.00');   // loss only

        $csv = $this->actingAs($this->admin)->get('/app/reports/inventory/damage?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('Recorded value', $csv->streamedContent());
        $this->assertStringContainsString('Held as damaged, value', $csv->streamedContent());

        // Someone who may read stock but not record damage or value it.
        $reader = $this->makeUser(['name' => 'Warehouse Viewer']);
        $reader->roles()->attach($this->roleWith(['portal.erp.access', 'dashboard.view', 'inventory.stock.view'])->id);

        $this->actingAs($reader)->get('/app/inventory/damage')->assertOk();
        $this->actingAs($reader)->get('/app/inventory/writeoffs')->assertOk();
        $this->actingAs($reader)->get('/app/inventory/damage/create')->assertForbidden();
        $this->actingAs($reader)->get('/app/inventory/loss/create')->assertForbidden();
        $this->actingAs($reader)->get('/app/inventory/writeoffs/create')->assertForbidden();
        $this->actingAs($reader)->get('/app/reports/inventory/damage')->assertForbidden();
    }

    public function test_a_writeoff_cannot_be_approved_by_the_person_who_raised_it_over_http(): void
    {
        $product = $this->stocked('HTTP-2', 4);

        $this->damage()->recordEntry(StockDamageEntry::KIND_DAMAGE, [
            'warehouse_id' => $this->warehouse->id,
            'entry_date' => now()->toDateString(),
            'reason' => 'Water damage.',
            'lines' => [['product_id' => $product->id, 'qty' => 1]],
        ], $this->admin);

        $writeoff = $this->damage()->raiseWriteoff([
            'warehouse_id' => $this->warehouse->id,
            'writeoff_date' => now()->toDateString(),
            'source_state' => StockMovement::STATE_DAMAGED,
            'reason' => 'Dump it.',
            'lines' => [['product_id' => $product->id, 'qty' => 1]],
        ], $this->admin);

        $this->actingAs($this->admin)
            ->from(route('inventory.writeoffs.index'))
            ->post(route('inventory.writeoffs.approve', $writeoff))
            ->assertSessionHasErrors('writeoff');

        $this->assertSame('1.0000', (string) $this->balance($product)->damaged, 'still held: nobody approved it');

        // A user without the approval capability cannot even try.
        $clerk = $this->makeUser(['name' => 'Stock Clerk']);
        $clerk->roles()->attach($this->roleWith([
            'portal.erp.access', 'dashboard.view', 'inventory.stock.view', 'inventory.writeoffs.create',
        ])->id);

        $this->actingAs($clerk)->post(route('inventory.writeoffs.approve', $writeoff))->assertForbidden();

        // And the queue shows the document as pending, with the reason it needs
        // a decision rather than a number that pretends it is done.
        $this->actingAs($this->admin)
            ->get('/app/inventory/writeoffs')
            ->assertOk()
            ->assertSee($writeoff->code);
    }
}
