<?php

namespace Tests\Feature;

use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\Services\StockReportService;
use App\Domain\Inventory\StockMovement;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §04-35/04-36 — stock ageing, dead stock and the stock report.
 *
 * What this pins:
 *  · a row is dated by the last movement of that product in that warehouse, so
 *    "idle" means nobody touched it — not that a report table says so;
 *  · value comes from the valuation layers, with the standard cost standing in
 *    only when a product carries no layers;
 *  · the dead-stock threshold is a setting, and the list always reports which
 *    threshold produced it;
 *  · report figures are the ledger's figures: they agree with the balances the
 *    rest of inventory reads.
 */
class StockReportTest extends TestCase
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
        $request = Request::create('/__stock-reports', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    /** A product with opening stock, optionally back-dated in the ledger. */
    protected function stocked(string $sku, float $qty, float $unitCost = 100, ?int $idleDays = null): Product
    {
        $product = app(CreateProduct::class)->handle([
            'code' => $sku,
            'sku' => $sku,
            'name' => 'Report probe '.$sku,
            'cost_method' => 'wac',
            'standard_cost' => $unitCost,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => $qty, 'unit_cost' => $unitCost]],
            'idempotency_suffix' => uniqid('report-', true),
        ], $this->httpRequest());

        if ($idleDays !== null) {
            // Fixture only: back-date the ledger row so the report has an age to
            // find. Nothing in application code writes these timestamps.
            StockMovement::query()
                ->where('product_id', $product->id)
                ->update(['occurred_at' => now()->subDays($idleDays)]);
        }

        return $product;
    }

    public function test_ageing_dates_a_row_from_its_last_movement_and_values_it_from_the_layers(): void
    {
        $this->stocked('AGE-40', 10, 50, idleDays: 40);
        $this->stocked('AGE-FRESH', 4, 25, idleDays: 5);

        $result = app(StockReportService::class)->aging($this->admin->company_id);
        $rows = collect($result['rows'])->keyBy(fn ($row) => $row['product']->sku);

        $this->assertSame(40, $rows['AGE-40']['days_idle']);
        $this->assertSame('31-60', $rows['AGE-40']['bucket']);
        $this->assertSame(500.0, $rows['AGE-40']['value'], '10 × 50 from the remaining layers');

        $this->assertSame('0-30', $rows['AGE-FRESH']['bucket']);

        $this->assertSame(1, $result['buckets']['31-60']['rows']);
        $this->assertSame(500.0, $result['buckets']['31-60']['value']);
        $this->assertSame(2, $result['totals']['rows']);
        $this->assertSame(600.0, $result['totals']['value']);
    }

    public function test_dead_stock_uses_the_threshold_from_settings_and_says_so(): void
    {
        $this->stocked('DEAD-100', 3, 100, idleDays: 100);
        $this->stocked('MOVING-40', 2, 100, idleDays: 40);

        $service = app(StockReportService::class);

        // Default: 90 days.
        $default = $service->deadStock($this->admin->company_id);
        $this->assertSame(90, $default['threshold']);
        $this->assertSame(['DEAD-100'], $default['rows']->pluck('product.sku')->all());

        // The threshold is a setting, not a constant.
        app(SettingService::class)->set('inventory', 'dead_stock_days', 30, null, $this->admin);
        $service = app(StockReportService::class);

        $this->assertSame(30, $service->deadStockDays());

        $tightened = $service->deadStock($this->admin->company_id);
        $this->assertSame(30, $tightened['threshold']);
        $this->assertEqualsCanonicalizing(['DEAD-100', 'MOVING-40'], $tightened['rows']->pluck('product.sku')->all());
    }

    public function test_the_stock_report_totals_are_the_ledgers_totals(): void
    {
        $a = $this->stocked('RPT-A', 10, 20);
        $this->stocked('RPT-B', 5, 60);

        // A hold moves nothing on hand, only what is still available.
        app(\App\Domain\Sales\Services\ReservationService::class)->reserve(
            $this->admin->company_id,
            $this->warehouse->id,
            'sales_order',
            7001,
            [['product_id' => $a->id, 'qty' => 4]],
        );

        $report = app(StockReportService::class)->stock($this->admin->company_id);

        $this->assertSame(15.0, $report['totals']['on_hand']);
        $this->assertSame(4.0, $report['totals']['reserved']);
        $this->assertSame(11.0, $report['totals']['available']);
        $this->assertSame(500.0, $report['totals']['value'], '10 × 20 + 5 × 60');

        $row = collect($report['rows'])->firstWhere(fn ($r) => $r['product']->sku === 'RPT-A');
        $this->assertSame(10.0, $row['on_hand'], 'a hold never changes on hand');
        $this->assertSame(6.0, $row['available']);
    }

    public function test_the_reports_are_permission_gated_and_export_csv(): void
    {
        $this->stocked('RPT-CSV', 2, 10);

        $this->actingAs($this->admin)->get('/app/reports/inventory/aging')->assertOk();
        $this->actingAs($this->admin)->get('/app/reports/inventory/dead-stock')->assertOk();
        $this->actingAs($this->admin)->get('/app/reports/inventory/stock')->assertOk();

        $csv = $this->actingAs($this->admin)->get('/app/reports/inventory/stock?format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('RPT-CSV', $csv->streamedContent());

        $outsider = $this->makeUser();
        $outsider->roles()->attach($this->roleWith(['portal.erp.access', 'inventory.stock.view'])->id);

        // Seeing stock is not the same as seeing the report family.
        $this->actingAs($outsider)->get('/app/reports/inventory/aging')->assertForbidden();
        $this->actingAs($outsider)->get('/app/reports/inventory/dead-stock')->assertForbidden();
        $this->actingAs($outsider)->get('/app/reports/inventory/stock')->assertForbidden();
    }
}
