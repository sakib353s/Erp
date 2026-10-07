<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Masters\SmsProvider;
use App\Domain\Notification\DeliveryLog;
use App\Domain\Notification\MessageTemplate;
use App\Domain\Notification\OutboxMessage;
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
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-11 Bulk WhatsApp: BulkNotifyAction(whatsapp) shares the outbox
 * machinery with SMS. The system has NO WhatsApp transport registry at
 * all — so every WhatsApp message is truthfully HELD
 * (not_configured), even when an SMS provider is fully configured
 * (SMS credentials never masquerade as a WhatsApp sender), and nothing
 * is ever marked sent. Placeholders render from real rows, per-order
 * failures report exactly why, and the route/button share the
 * sales.orders.notify gate.
 */
class BulkWhatsAppTest extends TestCase
{
    use CreatesERPInstance;
    use RefreshDatabase;

    protected User $admin;

    protected Warehouse $warehouse;

    protected Product $product;

    protected MessageTemplate $template;

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

        $this->template = MessageTemplate::query()
            ->whereNull('company_id')
            ->where('channel', 'whatsapp')
            ->where('code', 'order_update')
            ->firstOrFail();

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'BWA-1',
            'sku' => 'BWA-SKU-1',
            'name' => 'WhatsApp Product',
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
            'idempotency_suffix' => 'bwa-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-whatsapp', 'POST', [], [], [], [
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
            'code' => 'WAC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'WhatsApp Customer',
            'phone' => '01788888888',
            'address_line1' => 'Road 1, Uttara',
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

    public function test_whatsapp_is_always_held_even_when_sms_provider_is_configured(): void
    {
        $provider = SmsProvider::query()
            ->where('company_id', $this->admin->company_id)
            ->orderBy('id')
            ->firstOrFail();
        $provider->forceFill(['config_status' => 'configured'])->save();

        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('0 queued', $status);
        $this->assertStringContainsString('1 held', $status);
        $this->assertStringContainsString('0 failed', $status);

        $message = OutboxMessage::query()->firstOrFail();
        $this->assertSame('whatsapp', $message->channel);
        $this->assertSame(OutboxMessage::STATUS_NOT_CONFIGURED, $message->status);
        $this->assertNull($message->provider_code);
        $this->assertNull($message->sent_at);
        $this->assertSame('01788888888', $message->recipient);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('no WhatsApp provider is configured', $messages);

        $this->assertSame(0, OutboxMessage::query()->where('status', 'sent')->count());
        $this->assertSame(0, DeliveryLog::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'sales.order_bulk_whatsapp')->count());
    }

    public function test_body_renders_placeholders_from_real_rows(): void
    {
        $customer = $this->makeCustomer(['name' => 'Kamal Hossain']);
        $order = $this->makeConfirmedOrder($customer, 3);
        $companyName = (string) Company::query()->findOrFail($this->admin->company_id)->name;

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $message = OutboxMessage::query()->firstOrFail();
        $this->assertStringContainsString($order->order_no, $message->body);
        $this->assertStringContainsString('Kamal Hossain', $message->body);
        $this->assertStringContainsString($companyName, $message->body);
        $this->assertStringNotContainsString('{order_no}', $message->body);
    }

    public function test_orders_without_customer_or_phone_fail_with_that_message(): void
    {
        $good = $this->makeConfirmedOrder($this->makeCustomer(), 3);
        $noCustomer = $this->makeConfirmedOrder(null, 3);
        $noPhone = $this->makeConfirmedOrder($this->makeCustomer(['phone' => null]), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$good->id, $noCustomer->id, $noPhone->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 held', $status);
        $this->assertStringContainsString('2 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('no customer for the whatsapp message', $messages);
        $this->assertStringContainsString('no phone number for the whatsapp message', $messages);

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_route_and_button_respect_sales_orders_notify(): void
    {
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Send WhatsApp');

        $allowed = $this->makeUser(['name' => 'Notifier']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.orders.notify'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Send WhatsApp');

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_template_channel_is_enforced_in_both_directions(): void
    {
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $smsTemplate = MessageTemplate::query()
            ->whereNull('company_id')
            ->where('channel', 'sms')
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$order->id],
                'template_id' => $smsTemplate->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');
        $message = $this->app['session']->get('errors')->first('order');
        $this->assertStringContainsString('not available for this channel', $message);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_missing_orders_fail_without_queuing(): void
    {
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.whatsapp'), [
                'order_ids' => [$order->id, 99999999],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 held', $status);
        $this->assertStringContainsString('1 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('Order not found', $messages);

        $this->assertSame(1, OutboxMessage::query()->count());
    }
}
