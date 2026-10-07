<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Documents\Document;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\District;
use App\Domain\Notification\DeliveryLog;
use App\Domain\Notification\MessageTemplate;
use App\Domain\Notification\OutboxMessage;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
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
 * 02-12 Bulk email: BulkNotifyAction(email) — recipient is the real
 * customer email; a REAL mail driver (smtp/ses/…) queues the message,
 * the dev log/array drivers hold it as not_configured (no fake sent,
 * no delivery attempts); an email attaches the invoice document only
 * when it has actually been printed (HTML — no PDF engine exists, the
 * attachment says so instead of claiming pdf); route/button share the
 * sales.orders.notify gate.
 */
class BulkEmailTest extends TestCase
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
            ->where('channel', 'email')
            ->where('code', 'order_update')
            ->firstOrFail();

        $this->warehouse = Warehouse::query()
            ->where('company_id', $this->admin->company_id)
            ->where('code', 'MAIN')
            ->firstOrFail();

        $this->product = app(CreateProduct::class)->handle([
            'code' => 'BEL-1',
            'sku' => 'BEL-SKU-1',
            'name' => 'Email Product',
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
            'idempotency_suffix' => 'bel-open-'.uniqid(),
        ], $this->httpRequest());
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__bulk-email', 'POST', [], [], [], [
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
            'code' => 'EMC-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Email Customer',
            'phone' => '01777777777',
            'email' => 'customer@example.com',
            'address_line1' => 'House 9, Banani',
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

    public function test_log_mailer_holds_email_and_never_fakes_a_send(): void
    {
        config(['mail.default' => 'log']);
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.email'), [
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
        $this->assertSame('email', $message->channel);
        $this->assertSame('customer@example.com', $message->recipient);
        $this->assertSame(OutboxMessage::STATUS_NOT_CONFIGURED, $message->status);
        $this->assertNull($message->provider_code);
        $this->assertNull($message->sent_at);
        $this->assertNull($message->attachments);

        $messages = implode(' ', (array) session('bulk_messages'));
        $this->assertStringContainsString('no mail provider is configured', $messages);

        $this->assertSame(0, OutboxMessage::query()->where('status', 'sent')->count());
        $this->assertSame(0, DeliveryLog::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'sales.order_bulk_email')->count());
    }

    public function test_real_mail_driver_queues_but_still_never_marks_sent(): void
    {
        config(['mail.default' => 'smtp']);
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.email'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $status = (string) session('status');
        $this->assertStringContainsString('1 queued', $status);
        $this->assertStringContainsString('0 held', $status);

        $message = OutboxMessage::query()->firstOrFail();
        $this->assertSame(OutboxMessage::STATUS_QUEUED, $message->status);
        $this->assertSame('smtp', $message->provider_code);
        $this->assertNotNull($message->queued_at);
        $this->assertNull($message->sent_at);

        $this->assertSame(0, OutboxMessage::query()->where('status', 'sent')->count());
        $this->assertSame(0, DeliveryLog::query()->count());
    }

    public function test_email_attaches_the_invoice_document_only_once_printed(): void
    {
        config(['mail.default' => 'smtp']);
        $customer = $this->makeCustomer();
        $printedOrder = $this->makeConfirmedOrder($customer, 3);
        $plainOrder = $this->makeConfirmedOrder($customer, 4);

        foreach ([$printedOrder, $plainOrder] as $order) {
            $invoice = app(CreateInvoiceFromOrder::class)->handle($order->fresh(), [], $this->httpRequest());
            app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
        }

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.print-invoice'), [
                'order_ids' => [$printedOrder->id],
            ])
            ->assertRedirect();

        $printedDocument = Document::query()->where('purpose', 'generated')->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.email'), [
                'order_ids' => [$printedOrder->id, $plainOrder->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect();

        $attached = OutboxMessage::query()->where('sales_order_id', $printedOrder->id)->firstOrFail();
        $this->assertNotNull($attached->attachments);
        $this->assertSame('html', $attached->attachments['format']);
        $this->assertSame($printedDocument->id, $attached->attachments['document_ids'][0]);
        $this->assertNotNull($attached->attachments['invoice_id']);

        $unattached = OutboxMessage::query()->where('sales_order_id', $plainOrder->id)->firstOrFail();
        $this->assertNull($unattached->attachments);
    }

    public function test_order_without_customer_email_fails_with_that_message(): void
    {
        config(['mail.default' => 'smtp']);
        $noEmail = $this->makeConfirmedOrder($this->makeCustomer(['email' => null]), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.email'), [
                'order_ids' => [$noEmail->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');

        $message = $this->app['session']->get('errors')->first('order');
        $this->assertStringContainsString('no email address', $message);
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_route_and_button_respect_sales_orders_notify(): void
    {
        config(['mail.default' => 'smtp']);
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $denied = $this->makeUser(['name' => 'Order Viewer']);
        $denied->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view'])->id);
        app(PermissionCatalog::class)->invalidate($denied);

        $this->actingAs($denied)
            ->post(route('sales.orders.bulk.email'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertForbidden();

        $this->actingAs($denied)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertDontSee('Send email');

        $allowed = $this->makeUser(['name' => 'Notifier']);
        $allowed->roles()->sync($this->roleWith(['portal.erp.access', 'sales.orders.view', 'sales.orders.notify'])->id);
        app(PermissionCatalog::class)->invalidate($allowed);

        $this->actingAs($allowed)
            ->get(route('sales.orders.index'))
            ->assertOk()
            ->assertSee('Send email');

        $this->actingAs($allowed)
            ->post(route('sales.orders.bulk.email'), [
                'order_ids' => [$order->id],
                'template_id' => $this->template->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_wrong_channel_template_is_rejected(): void
    {
        config(['mail.default' => 'smtp']);
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $smsTemplate = MessageTemplate::query()
            ->whereNull('company_id')
            ->where('channel', 'sms')
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.email'), [
                'order_ids' => [$order->id],
                'template_id' => $smsTemplate->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('order');
        $message = $this->app['session']->get('errors')->first('order');
        $this->assertStringContainsString('not available for this channel', $message);

        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_missing_orders_fail_without_queuing(): void
    {
        config(['mail.default' => 'smtp']);
        $order = $this->makeConfirmedOrder($this->makeCustomer(), 3);

        $this->actingAs($this->admin)
            ->post(route('sales.orders.bulk.email'), [
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
}
