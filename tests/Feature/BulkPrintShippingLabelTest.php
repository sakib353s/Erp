<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Delivery\Shipment;
use App\Domain\Delivery\ShippingLabel;
use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\PrintHistory;
use App\Domain\Foundation\NumberingRule;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Courier;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
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
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-09 Bulk Print Shipping Label: BulkPrintAction(shipping_label)
 * snapshots the receiver (customer name/phone/address/district),
 * allocates a sequential SL label_no through the numbering rule (a
 * missing rule fails the order — never a fabricated number), carries
 * courier + tracking from the shipment when one exists (null courier =
 * honest "Not assigned"), files the document + print_history rows,
 * audits sales.order_bulk_print_shipping_label, and the route/button
 * are gated by the new sales.delivery.print permission.
 */
class BulkPrintShippingLabelTest extends TestCase
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

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'BSL-1',
            'sku' => 'BSL-SKU-1',
            'name' => 'Shipping Label Product',
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
            'idempotency_suffix' => 'bsl-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-print-label', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        $district = District::query()->orderBy('id')->firstOrFail();

        return Customer::query()->create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'SLC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Ship To Customer',
            'phone' => '01711111111',
            'address_line1' => 'House 1, Road 2, Dhanmondi',
            'district_id' => $district->id,
            'is_active' => true,
        ], $overrides));
    }

    protected function makeConfirmedOrder(?Customer $customer, int $qty = 5): SalesOrder
    {
        $order = app(CreateSalesOrder::class)->handle([
            'customer_id' => $customer?->id,
            'warehouse_id' => $this->warehouse->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => $qty, 'unit_price' => 150],
            ],
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        return $order->fresh();
    }

    public function test_bulk_print_creates_labels_documents_history_and_audit(): void
    {
        $customer = $this->makeCustomer();
        $first = $this->makeConfirmedOrder($customer, 3);
        $second = $this->makeConfirmedOrder($customer, 4);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-shipping-label'), [
                'order_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('2 printed', $status);
        $this->assertStringContainsString('0 failed', $status);

        $labels = ShippingLabel::query()->orderBy('id')->get();
        $this->assertCount(2, $labels);
        $this->assertNotSame($labels[0]->label_no, $labels[1]->label_no);
        $this->assertMatchesRegularExpression('/^SL\/\d{4}\/\d+$/', $labels[0]->label_no);
        $this->assertSame('Ship To Customer', $labels[0]->receiver_name);
        $this->assertSame('01711111111', $labels[0]->receiver_phone);
        $this->assertSame('House 1, Road 2, Dhanmondi', $labels[0]->receiver_address);
        $this->assertNotNull($labels[0]->district);

        $documents = Document::query()->where('purpose', 'generated')->get();
        $this->assertCount(2, $documents);
        foreach ($labels as $label) {
            $document = $documents->firstWhere('owner_id', $label->id);
            $this->assertNotNull($document);
            $this->assertSame(ShippingLabel::class, $document->owner_type);
            $bytes = Storage::disk('local')->get($document->path);
            $this->assertStringContainsString('SHIPPING LABEL', $bytes);
            $this->assertStringContainsString($label->label_no, $bytes);
        }

        $this->assertSame(2, PrintHistory::query()->where('format', 'html')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'sales.order_bulk_print_shipping_label')->count());
    }

    public function test_label_carries_courier_and_tracking_from_the_shipment(): void
    {
        $courier = Courier::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => 'SLBX',
            'name' => 'Label Box Courier',
            'configuration_status' => 'configured',
            'integration_enabled' => true,
            'is_active' => true,
        ]);
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.assign-courier'), [
                'order_ids' => [$order->id],
                'courier_id' => $courier->id,
            ])
            ->assertRedirect();

        $shipment = Shipment::query()->where('sales_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-shipping-label'), ['order_ids' => [$order->id]])
            ->assertRedirect();

        $label = ShippingLabel::query()->firstOrFail();
        $this->assertSame($shipment->id, $label->shipment_id);
        $this->assertSame($courier->id, $label->courier_id);
        $this->assertSame($shipment->external_ref, $label->tracking_code);
        $this->assertNotNull($label->tracking_code);

        $bytes = Storage::disk('local')->get(
            Document::query()->where('owner_id', $label->id)->firstOrFail()->path,
        );
        $this->assertStringContainsString('Label Box Courier', $bytes);
        $this->assertStringContainsString((string) $label->tracking_code, $bytes);
    }

    public function test_orders_without_customer_or_phone_fail_with_that_message(): void
    {
        $withCustomer = $this->makeConfirmedOrder($this->makeCustomer(), 3);
        $withoutCustomer = $this->makeConfirmedOrder(null, 3);
        $withoutPhone = $this->makeConfirmedOrder(
            $this->makeCustomer(['phone' => null]),
            3,
        );

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-shipping-label'), [
                'order_ids' => [$withCustomer->id, $withoutCustomer->id, $withoutPhone->id],
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 printed', $status);
        $this->assertStringContainsString('2 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('no customer for the shipping label', $messages);
        $this->assertStringContainsString('no phone number for the shipping label', $messages);

        $this->assertSame(1, ShippingLabel::query()->count());
        $this->assertSame(1, Document::query()->count());
    }

    public function test_route_and_button_respect_sales_delivery_print(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeConfirmedOrder($customer, 3);

        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.print-shipping-label'), ['order_ids' => [$order->id]])
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Print shipping label');

        $allowed = $this->makeUser(['name' => 'Dispatch Clerk']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.delivery.print'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Print shipping label');

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.print-shipping-label'), ['order_ids' => [$order->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, ShippingLabel::query()->count());
    }

    public function test_foreign_and_missing_orders_fail_without_touching_labels(): void
    {
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-shipping-label'), [
                'order_ids' => [$order->id, 99999999],
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 printed', $status);
        $this->assertStringContainsString('1 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('Order not found', $messages);

        $this->assertSame(1, ShippingLabel::query()->count());
        $this->assertSame(1, Document::query()->count());
        $this->assertSame(1, PrintHistory::query()->count());
    }

    public function test_label_uses_the_shipping_label_document_type_and_numbering_rule(): void
    {
        $type = DocumentType::query()->where('code', 'shipping_label')->firstOrFail();
        $this->assertSame('SHIPPING LABEL', $type->printed_title);
        $this->assertFalse((bool) $type->tax_applicable);

        $rule = NumberingRule::query()
            ->where('company_id', $this->admin->company_id)
            ->where('document_type_id', $type->id)
            ->where('branch_id', 0)
            ->where('is_active', true)
            ->firstOrFail();
        $this->assertSame('SL', $rule->prefix);

        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);
        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-shipping-label'), ['order_ids' => [$order->id]])
            ->assertRedirect();

        $label = ShippingLabel::query()->firstOrFail();
        $this->assertStringStartsWith('SL/', $label->label_no);
        $this->assertSame($this->admin->id, $label->created_by);

        $row = PrintHistory::query()->firstOrFail();
        $this->assertSame(ShippingLabel::class, $row->printable_type);
        $this->assertSame($label->id, $row->printable_id);
        $this->assertSame($type->id, $row->document_type_id);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertNotNull($row->ip);
    }
}
