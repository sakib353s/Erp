<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Actions\BulkPriceUpdate;
use App\Domain\Masters\PriceBulkUpdate;
use App\Domain\Masters\PriceList;
use App\Domain\Masters\PriceListItem;
use App\Domain\Masters\ProductPriceHistory;
use App\Domain\Workflow\ApprovalRequest;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\Workflow\WorkflowApprover;
use App\Domain\Workflow\WorkflowDefinition;
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
 * 02-109 Bulk Price Update: preview (pure) → confirm (queued batch,
 * applied or held for workflow approval when the change meets the
 * threshold), append-only price history and an audited diff per batch.
 */
class BulkPriceUpdateTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected Product $other;

    protected PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->product = $this->makeProduct('BPU-1', 'Bulk Probe One');
        $this->other = $this->makeProduct('BPU-2', 'Bulk Probe Two');

        $this->list = PriceList::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'BULK-A',
            'name' => 'Bulk list A',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_default' => false,
        ]);

        PriceListItem::query()->create([
            'price_list_id' => $this->list->id,
            'product_id' => $this->product->id,
            'price' => 100,
        ]);
        PriceListItem::query()->create([
            'price_list_id' => $this->list->id,
            'product_id' => $this->other->id,
            'price' => 200,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-price-update', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(string $code, string $name): Product
    {
        return app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());
    }

    private function priceOf(Product $product): float
    {
        return (float) PriceListItem::query()
            ->where('price_list_id', $this->list->id)
            ->where('product_id', $product->id)
            ->firstOrFail()
            ->price;
    }

    /** @return array<string, mixed> */
    private function percentPayload(float $percent, array $overrides = []): array
    {
        return array_merge([
            'mode' => 'confirm',
            'price_list_id' => $this->list->id,
            'change_type' => 'percent',
            'percent' => $percent,
        ], $overrides);
    }

    public function test_preview_is_pure_and_shows_the_computed_diff(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), [
                'mode' => 'preview',
                'price_list_id' => $this->list->id,
                'change_type' => 'percent',
                'percent' => -10,
            ]);

        $response->assertOk()
            ->assertSee('Preview — 2 matched')
            ->assertSee('90.00')
            ->assertSee('180.00')
            ->assertSee('would change');

        // Pure: no batch, no history, no price touched.
        $this->assertSame(0, PriceBulkUpdate::query()->count());
        $this->assertSame(0, ProductPriceHistory::query()->count());
        $this->assertEqualsWithDelta(100.0, $this->priceOf($this->product), 0.0001);
        $this->assertEqualsWithDelta(200.0, $this->priceOf($this->other), 0.0001);
    }

    public function test_confirm_applies_percent_change_writes_history_and_audit_diff(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(-10))
            ->assertRedirect(route('pricing.bulk-update'));

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('applied: 2 price(s) updated', $status);

        $this->assertEqualsWithDelta(90.0, $this->priceOf($this->product), 0.0001);
        $this->assertEqualsWithDelta(180.0, $this->priceOf($this->other), 0.0001);

        $batch = PriceBulkUpdate::query()->firstOrFail();
        $this->assertSame(PriceBulkUpdate::STATUS_APPLIED, $batch->status);
        $this->assertSame(2, $batch->row_count);
        $this->assertNotNull($batch->applied_at);
        $this->assertSame((float) 20, (float) $batch->threshold_pct);

        $history = ProductPriceHistory::query()->orderBy('product_id')->get();
        $this->assertCount(2, $history);
        $this->assertSame('bulk_update', $history[0]->source);
        $this->assertSame($batch->id, $history[0]->price_bulk_update_id);
        $this->assertSame($this->admin->id, $history[0]->changed_by);
        $this->assertEqualsWithDelta(100.0, (float) $history[0]->old_price, 0.0001);
        $this->assertEqualsWithDelta(90.0, (float) $history[0]->new_price, 0.0001);
        $this->assertEqualsWithDelta(-10.0, (float) $history[0]->percent_change, 0.0001);

        $audit = AuditEvent::query()
            ->where('action', 'sales.price_bulk_update')
            ->where('entity_type', 'price_bulk_update')
            ->where('entity_id', $batch->id)
            ->firstOrFail();
        $after = $audit->after;
        $this->assertSame(2, $after['changed']);
        $this->assertEqualsWithDelta(10.0, (float) $after['max_abs_pct'], 0.0001);
        $this->assertCount(2, $after['diffs']);
        $this->assertSame(90.0, (float) $after['diffs'][0]['new']);
    }

    public function test_confirm_set_price_only_touches_the_selected_product_codes(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), [
                'mode' => 'confirm',
                'price_list_id' => $this->list->id,
                'change_type' => 'set',
                'set_price' => 149.99,
                'product_codes' => 'BPU-1',
                'note' => 'Probe promo',
            ])
            ->assertRedirect(route('pricing.bulk-update'));

        $this->assertEqualsWithDelta(149.99, $this->priceOf($this->product), 0.0001);
        $this->assertEqualsWithDelta(200.0, $this->priceOf($this->other), 0.0001);

        $batch = PriceBulkUpdate::query()->firstOrFail();
        $this->assertSame(1, $batch->row_count);
        $this->assertSame('Probe promo', $batch->note);

        $history = ProductPriceHistory::query()->get();
        $this->assertCount(1, $history);
        $this->assertSame($this->product->id, $history[0]->product_id);
        $this->assertEqualsWithDelta(149.99, (float) $history[0]->new_price, 0.0001);
    }

    public function test_large_change_is_held_for_approval_then_applies_once_approved(): void
    {
        $role = $this->roleWith([]);
        $approver = $this->makeUser(['name' => 'Price Approver']);
        $approver->roles()->sync([$role->id]);

        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => BulkPriceUpdate::ENTITY_TYPE,
            'action' => BulkPriceUpdate::APPROVAL_ACTION,
            'name' => 'Bulk price update approval',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(50))
            ->assertRedirect(route('pricing.bulk-update'));

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('held for approval', $status);

        // Held: nothing written, prices untouched, approval open.
        $this->assertEqualsWithDelta(100.0, $this->priceOf($this->product), 0.0001);
        $this->assertEqualsWithDelta(200.0, $this->priceOf($this->other), 0.0001);
        $this->assertSame(0, ProductPriceHistory::query()->count());

        $batch = PriceBulkUpdate::query()->firstOrFail();
        $this->assertSame(PriceBulkUpdate::STATUS_PENDING, $batch->status);

        $approval = ApprovalRequest::query()
            ->where('entity_type', BulkPriceUpdate::ENTITY_TYPE)
            ->where('entity_id', $batch->id)
            ->where('status', 'pending')
            ->firstOrFail();

        // Approve → the listener re-queues the batch → it applies.
        app(WorkflowEngine::class)->approve($approval->id, $approver, 'ok');

        $batch->refresh();
        $this->assertSame(PriceBulkUpdate::STATUS_APPLIED, $batch->status);
        $this->assertSame(2, $batch->row_count);
        $this->assertEqualsWithDelta(150.0, $this->priceOf($this->product), 0.0001);
        $this->assertEqualsWithDelta(300.0, $this->priceOf($this->other), 0.0001);
        $this->assertSame(2, ProductPriceHistory::query()->count());
        $this->assertSame(
            2,
            AuditEvent::query()->where('action', 'sales.price_bulk_update')->count(),
        );
    }

    public function test_large_change_without_a_workflow_definition_applies_directly(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(50))
            ->assertRedirect(route('pricing.bulk-update'));

        $status = session('status');
        $this->assertIsString($status);
        $this->assertStringContainsString('applied: 2 price(s) updated', $status);

        $this->assertEqualsWithDelta(150.0, $this->priceOf($this->product), 0.0001);
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertSame(
            PriceBulkUpdate::STATUS_APPLIED,
            PriceBulkUpdate::query()->firstOrFail()->status,
        );
    }

    public function test_below_threshold_never_creates_an_approval_request(): void
    {
        $role = $this->roleWith([]);
        $definition = WorkflowDefinition::create([
            'company_id' => $this->admin->company_id,
            'entity_type' => BulkPriceUpdate::ENTITY_TYPE,
            'action' => BulkPriceUpdate::APPROVAL_ACTION,
            'name' => 'Bulk price update approval',
            'is_active' => true,
            'priority' => 10,
            'current_version' => 1,
            'approval_mode' => 'sequential',
            'block_self_approval' => true,
        ]);
        WorkflowApprover::create([
            'workflow_definition_id' => $definition->id,
            'approver_type' => 'role',
            'role_id' => $role->id,
            'level' => 1,
            'is_required' => true,
            'position' => 0,
        ]);

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(10))
            ->assertRedirect(route('pricing.bulk-update'));

        $this->assertEqualsWithDelta(110.0, $this->priceOf($this->product), 0.0001);
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertSame(
            PriceBulkUpdate::STATUS_APPLIED,
            PriceBulkUpdate::query()->firstOrFail()->status,
        );
    }

    public function test_invalid_payloads_are_rejected_without_writing(): void
    {
        $shadowListId = (int) DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Pricing Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignList = (int) DB::table('price_lists')->insertGetId([
            'company_id' => $shadowListId,
            'code' => 'FOREIGN-L',
            'name' => 'Foreign list',
            'valid_from' => now()->subDay()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'is_active' => 1,
            'is_default' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(-150))
            ->assertSessionHasErrors('percent');

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(5, [
                'change_type' => 'set',
                'set_price' => -5,
            ]))
            ->assertSessionHasErrors('set_price');

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), [
                'mode' => 'confirm',
                'price_list_id' => $this->list->id,
                'change_type' => 'percent',
            ])
            ->assertSessionHasErrors('percent');

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(10, [
                'price_list_id' => $foreignList,
            ]))
            ->assertSessionHasErrors('price_list_id');

        $this->actingAs($this->admin)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(10, [
                'product_codes' => 'NOPE-1',
            ]))
            ->assertSessionHasErrors('product_codes');

        $this->assertSame(0, PriceBulkUpdate::query()->count());
        $this->assertSame(0, ProductPriceHistory::query()->count());
        $this->assertEqualsWithDelta(100.0, $this->priceOf($this->product), 0.0001);
    }

    public function test_routes_require_the_bulk_update_permission(): void
    {
        $denied = $this->makeUser(['name' => 'List Manager Only']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.manage'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('pricing.bulk-update'))
            ->assertForbidden();
        $this->actingAs($denied)
            ->post(route('pricing.bulk-update.store'), $this->percentPayload(-10))
            ->assertForbidden();

        $allowed = $this->makeUser(['name' => 'Bulk Updater']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'pricing.bulk_update'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('pricing.bulk-update'))
            ->assertOk();
    }
}
