<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Reporting\OrderExport;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\SalesOrder;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-13 Export Orders: GET /app/sales/orders/export queues a
 * scope-filtered export (company + the requester's branch visibility,
 * status/search filters) through OrderExporter; the job files the CSV
 * as a real document (checksum/size from the bytes), completes the
 * export row, and records sales.orders_export. Scope can never widen:
 * other branches and other companies stay out of the file, and the
 * route/button are gated by the new sales.orders.export permission.
 */
class OrderExportTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->bootInstance();
        $this->bindTenantContext($this->admin, $this->defaultBranch());

        $this->seed(DocumentTypeSeeder::class);
        $this->seed(FoundationPermissionSeeder::class);
        $this->seed(NavigationSeeder::class);
        $this->seed(InventoryCoreSeeder::class);
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(AccountingCoreSeeder::class);
        $this->seed(SalesCoreSeeder::class);
        $this->seed(MessageTemplateSeeder::class);

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'EXP-1',
            'sku' => 'EXP-SKU-1',
            'name' => 'Export Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 500, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'exp-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__order-export', 'GET', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    private function secondBranch(string $code, string $name): Branch
    {
        return Branch::create([
            'company_id' => Company::current()?->id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function makeOrder(?User $asUser = null, bool $confirm = true): SalesOrder
    {
        $request = $this->httpRequest();
        if ($asUser !== null) {
            $request->setUserResolver(fn () => $asUser);
        }

        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3, 'unit_price' => 150],
            ],
        ], $request);

        if ($confirm) {
            app(ConfirmOrder::class)->handle($order, $request);
        }

        return $order->fresh();
    }

    protected function csvOfLatestExport(): string
    {
        $export = OrderExport::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(OrderExport::STATUS_COMPLETED, $export->status);
        $this->assertNotNull($export->completed_at);

        $document = Document::query()->findOrFail($export->document_id);
        $this->assertSame('text/csv', $document->mime_type);
        $bytes = Storage::disk('local')->get($document->path);
        $this->assertSame($document->checksum, hash('sha256', $bytes));

        return $bytes;
    }

    public function test_export_produces_a_csv_document_completes_and_audits(): void
    {
        $first = $this->makeOrder();
        $second = $this->makeOrder();

        $response = $this->actingAs($this->admin)->get(route('sales.orders.export'));
        $response->assertOk();
        $response->assertDownload();

        $export = OrderExport::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(OrderExport::STATUS_COMPLETED, $export->status);
        $this->assertSame(2, $export->row_count);
        $this->assertSame($this->admin->id, $export->user_id);

        $csv = $this->csvOfLatestExport();
        $this->assertStringContainsString('order_no', $csv);
        $this->assertStringContainsString($first->order_no, $csv);
        $this->assertStringContainsString($second->order_no, $csv);

        $audit = AuditEvent::query()->where('action', 'sales.orders_export')->firstOrFail();
        $this->assertSame($export->id, $audit->entity_id);
        $this->assertSame(2, $audit->after['row_count']);
    }

    public function test_export_only_contains_orders_of_branches_the_requester_can_access(): void
    {
        $ctg = $this->secondBranch('CTG', 'Chittagong Depot');
        $ctgUser = $this->makeUser([
            'name' => 'CTG Clerk',
            'branch_scope' => 'assigned',
            'default_branch_id' => $ctg->id,
        ]);
        $ctgOrder = $this->makeOrder($ctgUser, confirm: false);
        $this->assertSame($ctg->id, $ctgOrder->branch_id);

        $homeOrder = $this->makeOrder();

        $limited = $this->makeUser([
            'name' => 'Branch Limited',
            'branch_scope' => 'assigned',
        ]);
        $limited->roles()->sync(
            $this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.orders.export'])->id,
        );
        app(PermissionCatalog::class)->invalidate($limited);

        $this->actingAs($limited)->get(route('sales.orders.export'))->assertDownload();

        $export = OrderExport::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(1, $export->row_count);

        $csv = $this->csvOfLatestExport();
        $this->assertStringContainsString($homeOrder->order_no, $csv);
        $this->assertStringNotContainsString($ctgOrder->order_no, $csv);

        // The unrestricted admin sees both branches.
        $this->actingAs($this->admin)->get(route('sales.orders.export'))->assertDownload();
        $this->assertSame(2, OrderExport::query()->orderByDesc('id')->firstOrFail()->row_count);
    }

    public function test_export_applies_the_status_filter(): void
    {
        $this->makeOrder(confirm: true);
        $draft = $this->makeOrder(confirm: false);

        $this->actingAs($this->admin)
            ->get(route('sales.orders.export', ['status' => 'pending']))
            ->assertDownload();

        $export = OrderExport::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(1, $export->row_count);
        $this->assertSame(['status' => 'pending'], $export->filters);

        $csv = $this->csvOfLatestExport();
        $this->assertStringContainsString($draft->order_no, $csv);
        $this->assertStringNotContainsString('confirmed', $csv);
    }

    public function test_export_never_reaches_another_companys_orders(): void
    {
        $mine = $this->makeOrder();

        $shadowCompanyId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Traders Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sales_orders')->insert([
            'company_id' => $shadowCompanyId,
            'order_no' => 'SO/SHADOW/0001',
            'status' => 'pending',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->get(route('sales.orders.export'))->assertDownload();

        $export = OrderExport::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(1, $export->row_count);

        $csv = $this->csvOfLatestExport();
        $this->assertStringContainsString($mine->order_no, $csv);
        $this->assertStringNotContainsString('SO/SHADOW/0001', $csv);
    }

    public function test_route_and_button_respect_sales_orders_export(): void
    {
        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.orders.export'))
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Export CSV');

        $allowed = $this->makeUser(['name' => 'Exporter']);
        $allowed->roles()->sync(
            $this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.orders.export'])->id,
        );
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Export CSV');

        $this->actingAs($allowed)
            ->get(route('sales.orders.export'))
            ->assertDownload();

        $this->assertSame(1, OrderExport::query()->count());
    }

    public function test_empty_result_completes_with_header_only(): void
    {
        $this->makeOrder();

        $this->actingAs($this->admin)
            ->get(route('sales.orders.export', ['status' => 'refunded']))
            ->assertDownload();

        $export = OrderExport::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(OrderExport::STATUS_COMPLETED, $export->status);
        $this->assertSame(0, $export->row_count);

        $csv = $this->csvOfLatestExport();
        $this->assertStringContainsString('order_no', $csv);
        $this->assertStringNotContainsString('SO/', $csv);
    }
}
