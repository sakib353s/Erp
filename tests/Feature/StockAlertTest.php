<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\ReorderPolicy;
use App\Domain\Inventory\Services\ReorderService;
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
 * §04-23/04-24 — stock alerts and the reorder policies they are judged by.
 *
 * What this pins:
 *  · an alert exists only where a policy says what "too low" means, and the
 *    figure comes from the ledger-derived balance, never a cached guess;
 *  · the four states are boundaries: 0 is out, at-or-below minimum is low,
 *    above maximum is over, and everything else is quiet;
 *  · a warehouse's own policy wins over the company-wide one;
 *  · thresholds are validated together, so a contradictory policy is refused
 *    with the numbers rather than producing noise for ever after.
 */
class StockAlertTest extends TestCase
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

    protected function httpRequest(): Request
    {
        $request = Request::create('/__stock-alerts', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(string $sku, array $overrides = []): Product
    {
        return app(CreateProduct::class)->handle(array_merge([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Alert probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $overrides), $this->httpRequest());
    }

    protected function stock(Product $product, float $qty, ?int $warehouseId = null): void
    {
        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => 100]],
            'idempotency_suffix' => uniqid('alert-', true),
        ], $this->httpRequest());
    }

    protected function policy(Product $product, array $levels, ?int $warehouseId = null): ReorderPolicy
    {
        return app(ReorderService::class)->savePolicy($product, array_merge([
            'min_level' => 10,
            'max_level' => 100,
            'reorder_point' => 20,
            'safety_stock' => 5,
            'reorder_qty' => 40,
            'lead_time_days' => 7,
        ], $levels), $warehouseId, $this->admin->id);
    }

    public function test_the_four_states_are_boundaries_and_not_guesses(): void
    {
        $out = $this->makeProduct('ALERT-OUT');
        $low = $this->makeProduct('ALERT-LOW');
        $quiet = $this->makeProduct('ALERT-OK');
        $over = $this->makeProduct('ALERT-OVER');

        $this->stock($out, 0);
        $this->stock($low, 5);
        $this->stock($quiet, 50);
        $this->stock($over, 120);

        foreach ([$out, $low, $quiet, $over] as $product) {
            $this->policy($product, []);
        }

        $service = app(ReorderService::class);

        $outRows = $service->alertRows('out');
        $lowRows = $service->alertRows('low');
        $overRows = $service->alertRows('over');

        $this->assertSame(['ALERT-OUT'], array_map(fn ($row) => $row['product']->sku, $outRows['rows']));
        $this->assertSame(['ALERT-LOW'], array_map(fn ($row) => $row['product']->sku, $lowRows['rows']));
        $this->assertSame(['ALERT-OVER'], array_map(fn ($row) => $row['product']->sku, $overRows['rows']));

        $this->assertSame(5.0, $lowRows['rows'][0]['shortage'], 'minimum 10 with 5 on hand is 5 short');
        $this->assertSame(40.0, $lowRows['rows'][0]['suggested_qty'], 'the policy decides what to order');
        $this->assertSame(1, $lowRows['counts']['low']);
        $this->assertSame(1, $outRows['counts']['out']);
        $this->assertSame(1, $overRows['counts']['over']);

        // The quiet product is in no list at all — 50 sits between 10 and 100.
        $this->assertNotContains('ALERT-OK', array_map(fn ($row) => $row['product']->sku, $lowRows['rows']));
        $this->assertNotContains('ALERT-OK', array_map(fn ($row) => $row['product']->sku, $overRows['rows']));
    }

    public function test_a_product_without_a_policy_is_never_alerted(): void
    {
        $watched = $this->makeProduct('ALERT-WATCHED');
        $unwatched = $this->makeProduct('ALERT-UNWATCHED');

        $this->stock($watched, 1);
        $this->stock($unwatched, 1);

        $this->policy($watched, ['min_level' => 10]);

        $result = app(ReorderService::class)->alertRows('low');
        $skus = array_map(fn ($row) => $row['product']->sku, $result['rows']);

        $this->assertContains('ALERT-WATCHED', $skus);
        $this->assertNotContains('ALERT-UNWATCHED', $skus, 'no threshold means no claim');
    }

    public function test_a_warehouse_policy_wins_over_the_company_wide_rule(): void
    {
        $product = $this->makeProduct('ALERT-SCOPE');
        $this->stock($product, 30);

        // Company-wide: 30 is comfortable (min 10).
        $this->policy($product, ['min_level' => 10, 'max_level' => 200, 'reorder_point' => 20]);

        $this->assertSame([], app(ReorderService::class)->alertRows('low')['rows']);

        // The main warehouse wants a much higher floor.
        $this->policy($product, ['min_level' => 50, 'max_level' => 200, 'reorder_point' => 60, 'reorder_qty' => 100], $this->warehouse->id);

        $rows = app(ReorderService::class)->alertRows('low')['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame(20.0, $rows[0]['shortage'], 'judged by the warehouse rule: 50 − 30');
        $this->assertSame(100.0, $rows[0]['suggested_qty']);
        $this->assertSame('this warehouse', $rows[0]['scope']);
    }

    public function test_thresholds_are_validated_together_and_refused_with_the_numbers(): void
    {
        $product = $this->makeProduct('ALERT-RULES');
        $service = app(ReorderService::class);

        $cases = [
            [['min_level' => 150, 'max_level' => 100], 'cannot be above the maximum'],
            [['reorder_point' => 150, 'max_level' => 100], 'cannot be above the maximum'],
            [['min_level' => 40, 'reorder_point' => 20], 'below the minimum level'],
            [['safety_stock' => 50, 'reorder_point' => 20], 'cannot exceed the reorder point'],
            [['min_level' => -1], 'cannot be negative'],
            [['lead_time_days' => 400], 'between 0 and 365 days'],
        ];

        foreach ($cases as [$override, $expected]) {
            try {
                $this->policy($product, $override);
                $this->fail('Expected a refusal for '.json_encode($override));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($expected, $e->getMessage(), json_encode($override));
            }
        }

        // Nothing was written by a refused policy.
        $this->assertSame(0, ReorderPolicy::query()->where('product_id', $product->id)->count());
    }

    public function test_saving_again_replaces_the_row_rather_than_stacking_policies(): void
    {
        $product = $this->makeProduct('ALERT-ONE');

        $this->policy($product, ['min_level' => 10]);
        $this->policy($product, ['min_level' => 25, 'reorder_point' => 30]);

        $rows = ReorderPolicy::query()->where('product_id', $product->id)->get();

        $this->assertCount(1, $rows, 'one policy per product + warehouse');
        $this->assertSame('25.0000', (string) $rows->first()->min_level);
    }

    public function test_the_screens_are_permission_gated_and_the_configure_key_is_separate(): void
    {
        $product = $this->makeProduct('ALERT-HTTP');
        $this->stock($product, 1);

        $viewer = $this->makeUser();
        $viewer->roles()->attach($this->roleWith(['portal.erp.access', 'inventory.reorder.view'])->id);

        $this->actingAs($viewer)->get('/app/inventory/stock/alerts?type=low')->assertOk();
        $this->actingAs($viewer)->get('/app/inventory/reorder-levels')->assertOk();
        // Seeing the alerts does not grant the right to change the thresholds.
        $this->actingAs($viewer)->post('/app/inventory/reorder-levels', [
            'product_id' => $product->id,
            'min_level' => 10, 'max_level' => 100, 'reorder_point' => 20,
            'safety_stock' => 5, 'reorder_qty' => 40, 'lead_time_days' => 7,
        ])->assertForbidden();

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access'])->id);
        $this->actingAs($outsider)->get('/app/inventory/stock/alerts')->assertForbidden();
        $this->actingAs($outsider)->get('/app/inventory/reorder-levels')->assertForbidden();

        // With the configure key the policy saves and shows up in the list.
        $configurer = $this->makeUser();
        $configurer->roles()->attach($this->roleWith(['portal.erp.access', 'inventory.reorder.view', 'inventory.reorder.configure'])->id);

        $this->actingAs($configurer)->post('/app/inventory/reorder-levels', [
            'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id,
            'min_level' => 10, 'max_level' => 100, 'reorder_point' => 20,
            'safety_stock' => 5, 'reorder_qty' => 40, 'lead_time_days' => 7,
        ])->assertRedirect(route('inventory.reorder.index'));

        $this->actingAs($configurer)->get('/app/inventory/reorder-levels')
            ->assertOk()
            ->assertSee('ALERT-HTTP');

        $this->assertSame(1, ReorderPolicy::query()->where('product_id', $product->id)->count());
    }
}
