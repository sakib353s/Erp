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
 * 02-10 Bulk SMS: BulkNotifyAction(sms) queues per-order messages from
 * a structural template with placeholders rendered server-side.
 * Provider states are truthful — with the seeded not_configured
 * providers every message is HELD (status not_configured, no provider
 * code, NEVER sent); an active configured provider only moves them to
 * queued, still never sent from this action (no fabricated delivery
 * attempts). Per-order failures report exactly why, and the
 * route/button are gated by the new sales.orders.notify permission.
 */
class BulkSmsTest extends TestCase
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
            ->where('channel', 'sms')
            ->where('code', 'order_update')
            ->firstOrFail();

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'BSM-1',
            'sku' => 'BSM-SKU-1',
            'name' => 'Bulk SMS Product',
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
            'idempotency_suffix' => 'bsm-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-sms', 'POST', [], [], [], [
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
            'code' => 'SMC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'SMS Customer',
            'phone' => '01799999999',
            'address_line1' => 'Road 5, Mirpur',
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

    protected function configureProvider(): SmsProvider
    {
        $provider = SmsProvider::query()
            ->where('company_id', $this->admin->company_id)
            ->orderBy('id')
            ->firstOrFail();

        $provider->forceFill(['config_status' => 'configured'])->save();

        return $provider;
    }

    public function test_disabled_provider_holds_every_message_and_never_fakes_a_send(): void
    {
        $first = $this->makeConfirmedOrder($this->makeCustomer(), 3);
        $second = $this->makeConfirmedOrder($this->makeCustomer(), 4);

        $this->assertSame(
            'not_configured',
            SmsProvider::query()->where('company_id', $this->admin->company_id)->firstOrFail()->config_status,
        );

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$first->id, $second->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringContainsString('0 queued', $status);
        $this->assertStringContainsString('2 held', $status);
        $this->assertStringContainsString('0 failed', $status);

        $messages = OutboxMessage::query()->get();
        $this->assertCount(2, $messages);
        foreach ($messages as $message) {
            $this->assertSame(OutboxMessage::STATUS_NOT_CONFIGURED, $message->status);
            $this->assertNull($message->provider_code);
            $this->assertNull($message->sent_at);
            $this->assertSame($this->template->id, $message->message_template_id);
            $this->assertSame('01799999999', $message->recipient);
        }

        $this->assertSame(0, OutboxMessage::query()->where('status', 'sent')->count());
        $this->assertSame(0, DeliveryLog::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'sales.order_bulk_sms')->count());
    }

    public function test_configured_provider_queues_but_still_never_marks_sent(): void
    {
        $provider = $this->configureProvider();
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 queued', $status);
        $this->assertStringContainsString('0 held', $status);

        $message = OutboxMessage::query()->firstOrFail();
        $this->assertSame(OutboxMessage::STATUS_QUEUED, $message->status);
        $this->assertSame($provider->code, $message->provider_code);
        $this->assertNull($message->sent_at);
        $this->assertNotNull($message->queued_at);

        $this->assertSame(0, OutboxMessage::query()->where('status', 'sent')->count());
        $this->assertSame(0, DeliveryLog::query()->count());
    }

    public function test_body_renders_template_placeholders_from_real_rows(): void
    {
        $this->configureProvider();
        $customer = $this->makeCustomer(['name' => 'Rahima Begum']);
        $order = $this->makeConfirmedOrder($customer, 3);
        $companyName = (string) Company::query()->findOrFail($this->admin->company_id)->name;

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $message = OutboxMessage::query()->firstOrFail();
        $this->assertStringContainsString($order->order_no, $message->body);
        $this->assertStringContainsString('Rahima Begum', $message->body);
        $this->assertStringContainsString($companyName, $message->body);
        $this->assertStringNotContainsString('{order_no}', $message->body);
        $this->assertStringNotContainsString('{customer}', $message->body);
    }

    public function test_orders_without_customer_or_phone_fail_with_that_message(): void
    {
        $this->configureProvider();
        $good = $this->makeConfirmedOrder($this->makeCustomer(), 3);
        $noCustomer = $this->makeConfirmedOrder(null, 3);
        $noPhone = $this->makeConfirmedOrder($this->makeCustomer(['phone' => null]), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$good->id, $noCustomer->id, $noPhone->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 queued', $status);
        $this->assertStringContainsString('2 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('no customer for the sms message', $messages);
        $this->assertStringContainsString('no phone number for the sms message', $messages);

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_route_and_button_respect_sales_orders_notify(): void
    {
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Send SMS')
            ->assertDontSee('Message template');

        $allowed = $this->makeUser(['name' => 'Notifier']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.orders.notify'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Send SMS')
            ->assertSee('Message template');

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_foreign_and_missing_orders_fail_without_queuing(): void
    {
        $this->configureProvider();
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id, 99999999],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 queued', $status);
        $this->assertStringContainsString('1 failed', $status);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('Order not found', $messages);

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_template_validation_rejects_missing_foreign_and_wrong_channel_templates(): void
    {
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), ['order_ids' => [$order->id]])
            ->assertSessionHasErrors('template_id');

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => 99999999,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');
        $message = $this->app['session']->get('errors')->first('order');
        $this->assertStringContainsString('not available for this channel', $message);

        $emailTemplate = MessageTemplate::query()
            ->whereNull('company_id')
            ->where('channel', 'email')
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.sms'), [
                'order_ids' => [$order->id],
                'template_id' => $emailTemplate->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $this->assertSame(0, OutboxMessage::query()->count());
    }
}
