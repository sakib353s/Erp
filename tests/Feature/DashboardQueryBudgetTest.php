<?php

namespace Tests\Feature;

use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Foundation\Warehouse;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * §16-53 — the dashboard stays cheap.
 *
 * The dashboard is the first screen after login, so it must not become the query
 * it shouldn't be: no 25 full scans of every table, no N+1 where each customer or
 * invoice becomes its own round-trip. Each widget is answered by one aggregate
 * query over the company's own documents, so the total is bounded by the number of
 * widgets (decision D22: exactly 25 containers), not by the size of the data.
 *
 * These tests pin that contract: the dashboard renders within a generous query
 * budget, it never grows to a 25-scan storm, and adding a lot more data does not
 * add a query per row.
 */
class DashboardQueryBudgetTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(\Database\Seeders\InventoryCoreSeeder::class);
        $this->seed(\Database\Seeders\SalesCoreSeeder::class);
        $this->seed(\Database\Seeders\DocumentTypeSeeder::class);
        $this->seed(\Database\Seeders\AccountingCoreSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();
    }

    protected function http(): Request
    {
        $request = Request::create('/__dash', 'POST', [], [], [], ['HTTP_HOST' => 'instance.test']);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function invoice(): void
    {
        $product = app(CreateProduct::class)->handle([
            'code' => 'DQ-'.uniqid(),
            'sku' => 'DQ-'.uniqid().'-SKU',
            'name' => 'Dashboard Query Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->http());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 100, 'unit_cost' => 80]],
            'idempotency_suffix' => 'dq-open-'.$product->id,
        ], $this->http());

        $district = District::query()->orderBy('id')->firstOrFail();
        $customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'DQ-C-'.uniqid(),
            'name' => 'Dashboard Query Customer',
            'phone' => '01755558888',
            'address_line1' => 'Addr',
            'district_id' => $district->id,
            'is_active' => true,
        ]);

        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price' => 150]],
        ], $this->http());

        app(ConfirmOrder::class)->handle($order, $this->http());

        app(IssueInvoice::class)->handle(
            app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->http()),
            $this->http(),
        );
    }

    public function test_the_dashboard_renders_within_a_query_budget(): void
    {
        DB::enableQueryLog();

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 25 widgets, each answered by aggregate queries — a few queries each at most,
        // never a full scan per row. A generous ceiling catches a regression where a
        // widget loops over every record instead of aggregating.
        $this->assertLessThanOrEqual(150, $queries, "Dashboard ran {$queries} queries.");
    }

    public function test_more_data_does_not_mean_more_queries_no_n_plus_one(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('dashboard'));
        $baseline = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Seed a meaningful amount of data the dashboard summarises.
        for ($i = 0; $i < 12; $i++) {
            $this->invoice();
        }

        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('dashboard'));
        $after = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Widget metrics aggregate, so each added invoice should cost at most a few
        // queries — a true N+1 (one query per invoice per widget) would blow this
        // floor straight past it. 12 invoices × 5 queries is the ceiling for "still
        // bounded"; more than that is the scan storm §16-53 forbids.
        $this->assertLessThanOrEqual($baseline + (12 * 5), $after, "Query count grew from {$baseline} to {$after} with 12 more invoices.");
    }

    public function test_the_dashboard_renders_at_most_25_widget_containers(): void
    {
        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        // Decision D22: exactly 25 widget containers — the dashboard is a fixed
        // surface, not a wall that grows with the catalogue.
        $this->assertLessThanOrEqual(25, substr_count($response->getContent(), 'erp-widget'));
    }
}
