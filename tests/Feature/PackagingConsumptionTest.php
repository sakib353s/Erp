<?php

namespace Tests\Feature;

use App\Domain\Accounting\Account;
use App\Domain\Accounting\JournalEntry;
use App\Domain\Accounting\JournalLine;
use App\Domain\Accounting\PostingRule;
use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\PackagingType;
use App\Domain\Delivery\PackagingUsage;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Inventory\StockBalance;
use App\Domain\Inventory\StockMovement;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\SalesOrder;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-98 Packaging management at GET/POST /app/sales/delivery/packaging
 * behind sales.delivery.packaging:
 *
 *  - packaging types map to stock-managed products (non-stocked
 *    products refused, duplicate codes refused, no fake types seeded);
 *  - consumption posts a PACK_CONSUME movement through
 *    StockLedgerService — layer FIFO sets unit_cost (client input is
 *    ignored), insufficient stock is refused with the ledger's own
 *    reason, and the cost lands on sales_orders.packaging_cost;
 *  - ACCT when perpetual: Dr COGS / Cr inventory only while the
 *    sales_cost rule resolves; absent rule = usage still recorded,
 *    zero journals;
 *  - IssueInvoice never re-costs packaging (invoice COGS stays
 *    product-only) and every route is permission-gated.
 */
class PackagingConsumptionTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected Product $box;

    protected Customer $customer;

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

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'PCK-1',
            'sku' => 'PCK-SKU-1',
            'name' => 'Packed Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 200, 'unit_cost' => 80],
            ],
            'idempotency_suffix' => 'pck-open-'.uniqid(),
        ], $this->httpRequest());

        $this->box = app(CreateProduct::class)->handle([
            'code' => 'BOX-1',
            'sku' => 'BOX-SKU-1',
            'name' => 'Corrugated Box',
            'cost_method' => 'fifo',
            'standard_cost' => 12,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->box->id, 'qty' => 50, 'unit_cost' => 12.50],
            ],
            'idempotency_suffix' => 'box-open-'.uniqid(),
        ], $this->httpRequest());

        $district = District::query()->orderBy('id')->firstOrFail();
        $this->customer = Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'PKGC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Packaging Customer',
            'phone' => '01733333335',
            'address_line1' => 'House 9, Road 9, Uttara',
            'district_id' => $district->id,
            'is_active' => true,
        ]);
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__packaging', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeConfirmedOrder(int $qty = 5): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    protected function makeType(string $code = 'BOX'): PackagingType
    {
        return PackagingType::create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => 'Corrugated Box',
            'product_id' => $this->box->id,
            'is_active' => true,
        ]);
    }

    protected function consume(SalesOrder $order, PackagingType $type, float $qty = 3, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(
            route('sales.delivery.packaging.store'),
            $extra + [
                'sales_order_id' => $order->id,
                'packaging_type_id' => $type->id,
                'qty' => $qty,
            ],
        );
    }

    protected function lineSum(JournalEntry $entry, string $code, string $dc): float
    {
        $account = Account::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', $code)
            ->firstOrFail();

        return round((float) JournalLine::query()
            ->where('journal_entry_id', $entry->id)
            ->where('account_id', $account->id)
            ->where('dc', $dc)
            ->sum('amount'), 2);
    }

    public function test_create_packaging_type_requires_a_stocked_active_product(): void
    {
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.packaging.types.store'), [
                'code' => 'BOX',
                'name' => 'Corrugated Box',
                'product_id' => $this->box->id,
            ])
            ->assertRedirect(route('sales.delivery.packaging.index'))
            ->assertSessionHas('status', 'Packaging type created.');

        $type = PackagingType::query()->where('code', 'BOX')->firstOrFail();
        $this->assertSame((int) $this->box->id, (int) $type->product_id);
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', 'sales.packaging_type_created')->count(),
        );

        // Duplicate code within the company.
        $this->actingAs($this->admin)
            ->post(route('sales.delivery.packaging.types.store'), [
                'code' => 'BOX',
                'name' => 'Another box',
                'product_id' => $this->box->id,
            ])
            ->assertSessionHasErrors('code');

        // Non-stocked product refused with a truthful reason.
        $nonStocked = app(CreateProduct::class)->handle([
            'code' => 'NS-1',
            'sku' => 'NS-SKU-1',
            'name' => 'Service Label',
            'cost_method' => 'fifo',
            'standard_cost' => 0,
            'is_stocked' => false,
            'is_active' => true,
        ], $this->httpRequest());

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.packaging.types.store'), [
                'code' => 'LBL',
                'name' => 'Label',
                'product_id' => $nonStocked->id,
            ])
            ->assertSessionHasErrors('product_id');
        $this->assertStringContainsString(
            'stock-managed and active',
            (string) session('errors')->get('product_id')[0],
        );

        $this->assertSame(1, PackagingType::query()->count());
    }

    public function test_screen_lists_real_data_with_honest_empty_states(): void
    {
        // Structural seeder ships no packaging types — nothing fake.
        $this->assertSame(0, PackagingType::query()->count());

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.packaging.index'))
            ->assertOk()
            ->assertSee('No packaging types yet — every type maps to a real stock-managed product.')
            ->assertSee('No packaging consumed yet — consumption appears here as it is recorded.');

        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();
        $this->consume($order, $type, 3)->assertSessionHas('status');

        $this->actingAs($this->admin)
            ->get(route('sales.delivery.packaging.index'))
            ->assertOk()
            ->assertSee('BOX')
            ->assertSee($order->order_no)
            ->assertSee('37.50') // consumed cost total
            ->assertSee('3.000') // usage history qty (ledger truth)
            ->assertSee('12.5000'); // layer-valued unit cost
    }

    public function test_consume_posts_pack_consume_and_charges_the_order(): void
    {
        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();

        $this->consume($order, $type, 3)
            ->assertRedirect(route('sales.delivery.packaging.index'))
            ->assertSessionHas('status', 'Packaging consumed — stock and order cost updated.');

        $usage = PackagingUsage::query()->firstOrFail();
        $this->assertSame((int) $order->id, (int) $usage->sales_order_id);
        $this->assertSame((int) $this->box->id, (int) $usage->product_id);
        $this->assertSame((int) $this->warehouse->id, (int) $usage->warehouse_id);
        $this->assertSame((int) $this->admin->id, (int) $usage->consumed_by);
        $this->assertEqualsWithDelta(3.0, (float) $usage->qty, 0.001);
        $this->assertEqualsWithDelta(12.50, (float) $usage->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(37.50, (float) $usage->total_cost, 0.0001);

        $movement = StockMovement::query()->findOrFail($usage->stock_movement_id);
        $this->assertSame(StockMovement::TYPE_PACK_CONSUME, $movement->movement_type);
        $this->assertEqualsWithDelta(-3.0, (float) $movement->qty_signed, 0.0001);
        $this->assertEqualsWithDelta(12.50, (float) $movement->unit_cost, 0.0001);
        $this->assertSame('packaging_usage', $movement->source_type);
        $this->assertSame((int) $usage->id, (int) $movement->source_id);
        $this->assertSame("pack-usage:{$usage->id}", $movement->idempotency_key);

        $balance = StockBalance::query()
            ->where('product_id', $this->box->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(47.0, (float) $balance->on_hand, 0.0001);

        $order = $order->fresh();
        $this->assertEqualsWithDelta(37.50, (float) $order->packaging_cost, 0.0001);

        $audit = AuditEvent::query()
            ->where('action', 'sales.packaging_consumed')
            ->where('entity_id', $usage->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(37.50, (float) $audit->after['total_cost'], 0.0001);
        $this->assertEqualsWithDelta(37.50, (float) $audit->after['order_packaging_cost'], 0.0001);
        $this->assertSame((int) $movement->id, (int) $audit->after['stock_movement_id']);
        $this->assertNotNull($audit->after['journal_entry_id']);
    }

    public function test_consume_ignores_client_supplied_unit_cost(): void
    {
        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();

        $this->consume($order, $type, 3, ['unit_cost' => 0.01, 'total_cost' => 0.03])
            ->assertSessionHas('status');

        $usage = PackagingUsage::query()->firstOrFail();
        $this->assertEqualsWithDelta(12.50, (float) $usage->unit_cost, 0.0001);
        $this->assertEqualsWithDelta(37.50, (float) $usage->total_cost, 0.0001);
        $this->assertEqualsWithDelta(37.50, (float) $order->fresh()->packaging_cost, 0.0001);
    }

    public function test_consume_refused_when_stock_insufficient(): void
    {
        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();

        $this->consume($order, $type, 9999)
            ->assertSessionHasErrors('packaging');

        $this->assertStringContainsString(
            'Insufficient stock layers',
            (string) session('errors')->get('packaging')[0],
        );
        $this->assertSame(0, PackagingUsage::query()->count());
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_PACK_CONSUME)
                ->count(),
        );
        $this->assertEqualsWithDelta(0.0, (float) $order->fresh()->packaging_cost, 0.0001);

        $balance = StockBalance::query()
            ->where('product_id', $this->box->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(50.0, (float) $balance->on_hand, 0.0001);
    }

    public function test_consume_refused_without_warehouse_and_for_terminal_orders(): void
    {
        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();

        SalesOrder::query()->whereKey($order->id)->update(['warehouse_id' => null]);
        $this->consume($order, $type)->assertSessionHasErrors('packaging');
        $this->assertStringContainsString(
            'has no warehouse',
            (string) session('errors')->get('packaging')[0],
        );

        SalesOrder::query()->whereKey($order->id)->update([
            'warehouse_id' => $this->warehouse->id,
            'status' => 'cancelled',
        ]);
        $this->consume($order, $type)->assertSessionHasErrors('packaging');
        $this->assertStringContainsString(
            'cannot consume packaging from status',
            (string) session('errors')->get('packaging')[0],
        );

        $this->assertSame(0, PackagingUsage::query()->count());
    }

    public function test_foreign_order_and_type_rejected_by_validation(): void
    {
        $shadowId = DB::table('companies')->insertGetId([
            'singleton' => 0,
            'name' => 'Shadow Packaging Ltd',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowOrderId = DB::table('sales_orders')->insertGetId([
            'company_id' => $shadowId,
            'order_no' => 'SHDW-PKG',
            'status' => 'confirmed',
            'order_date' => now()->toDateString(),
            'grand_total' => 0,
            'packaging_cost' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $shadowTypeId = DB::table('packaging_types')->insertGetId([
            'company_id' => $shadowId,
            'code' => 'SHBOX',
            'name' => 'Shadow Box',
            'product_id' => $this->box->id,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post(route('sales.delivery.packaging.store'), [
                'sales_order_id' => $shadowOrderId,
                'packaging_type_id' => $shadowTypeId,
                'qty' => 1,
            ])
            ->assertSessionHasErrors(['sales_order_id', 'packaging_type_id']);

        $this->assertSame(0, PackagingUsage::query()->count());
        $this->assertSame(
            0,
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_PACK_CONSUME)
                ->count(),
        );
    }

    public function test_packaging_gl_posts_via_sales_cost_rule_when_perpetual(): void
    {
        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();
        $this->consume($order, $type, 3)->assertSessionHas('status');

        $entry = JournalEntry::query()
            ->where('source_type', 'packaging_usage')
            ->where('source_event', 'packaging_consumed')
            ->firstOrFail();
        $this->assertSame('sales', $entry->journal_type);
        $this->assertEqualsWithDelta(37.50, (float) $entry->total_debit, 0.0001);
        $this->assertEqualsWithDelta(37.50, (float) $entry->total_credit, 0.0001);
        $this->assertSame(37.50, $this->lineSum($entry, '5100', 'debit'));
        $this->assertSame(37.50, $this->lineSum($entry, '1140', 'credit'));
    }

    public function test_no_gl_when_sales_cost_rule_absent(): void
    {
        PostingRule::query()
            ->where('event_type', 'sales_cost')
            ->update(['is_active' => false]);

        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();
        $this->consume($order, $type, 3)->assertSessionHas('status');

        $usage = PackagingUsage::query()->firstOrFail();
        $this->assertEqualsWithDelta(37.50, (float) $usage->total_cost, 0.0001);
        $this->assertNotNull($usage->stock_movement_id);
        $this->assertEqualsWithDelta(37.50, (float) $order->fresh()->packaging_cost, 0.0001);

        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'packaging_usage')->count(),
        );
        $audit = AuditEvent::query()
            ->where('action', 'sales.packaging_consumed')
            ->firstOrFail();
        $this->assertNull($audit->after['journal_entry_id']);
    }

    public function test_invoice_cogs_never_includes_packaging(): void
    {
        $order = $this->makeConfirmedOrder();
        $type = $this->makeType();
        $this->consume($order, $type, 3)->assertSessionHas('status');

        $invoice = app(CreateInvoiceFromOrder::class)->handle($order, [], $this->httpRequest());
        app(IssueInvoice::class)->handle($invoice->fresh(), $this->httpRequest());

        // Invoice COGS = product only: 5 × 80 (layer cost), never +37.50.
        $invoiceEntry = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_event', 'sales_cost')
            ->firstOrFail();
        $this->assertEqualsWithDelta(400.0, (float) $invoiceEntry->total_debit, 0.0001);

        // Packaging kept its own journal — separate source, no double count.
        $packagingEntry = JournalEntry::query()
            ->where('source_type', 'packaging_usage')
            ->firstOrFail();
        $this->assertEqualsWithDelta(37.50, (float) $packagingEntry->total_debit, 0.0001);

        $this->assertSame(
            1,
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_PACK_CONSUME)
                ->count(),
        );
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('movement_type', StockMovement::TYPE_SALES_OUT)
                ->count(),
        );
    }

    public function test_routes_gated_by_sales_delivery_packaging(): void
    {
        $denied = $this->makeUser();
        $deniedRole = $this->roleWith(['portal.erp.access']);
        $denied->roles()->attach($deniedRole->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->get(route('sales.delivery.packaging.index'))
            ->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.delivery.packaging.store'), [
                'sales_order_id' => 1,
                'packaging_type_id' => 1,
                'qty' => 1,
            ])
            ->assertForbidden();
        $this->actingAs($denied)
            ->post(route('sales.delivery.packaging.types.store'), [
                'code' => 'X',
                'name' => 'X',
                'product_id' => 1,
            ])
            ->assertForbidden();

        $granted = $this->makeUser();
        $grantedRole = $this->roleWith(['portal.erp.access', 'sales.delivery.packaging']);
        $granted->roles()->attach($grantedRole->id);
        app(PermissionCatalog::class)->invalidate($granted);

        $this->actingAs($granted)
            ->get(route('sales.delivery.packaging.index'))
            ->assertOk();

        // Gate passed — validation is the next stop (no rows touched).
        $this->actingAs($granted)
            ->post(route('sales.delivery.packaging.store'), ['qty' => 1])
            ->assertSessionHasErrors(['sales_order_id', 'packaging_type_id']);
    }
}
