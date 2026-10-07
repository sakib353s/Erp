<?php

namespace Tests\Feature;

use App\Domain\Audit\AuditEvent;
use App\Domain\Foundation\Services\PermissionCatalog;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Notification\Notification;
use App\Domain\Outbox\OutboxEvent;
use App\Domain\Sales\Actions\ConvertQuotationToOrder;
use App\Domain\Sales\Actions\CreateQuotation;
use App\Domain\Sales\Events\QuotationSent;
use App\Domain\Sales\Quotation;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\CreatesERPInstance;
use Tests\TestCase;

/**
 * 02-67 Sent quotations: SendQuotation writes the outbox delivery event in
 * the same transaction as the status change, so `?status=sent` only ever
 * shows deliveries that really happened.
 */
class QuotationSentTest extends TestCase
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
            'code' => 'SENT-1',
            'sku' => 'SENT-SKU-1',
            'name' => 'Sendable Product',
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());
    }

    protected function httpRequest(?User $user = null): Request
    {
        $request = Request::create('/__quotation-send', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $user ?? $this->admin);

        return $request;
    }

    protected function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'company_id' => $this->admin->company_id,
            'code' => 'CUST-SENT',
            'name' => 'Send Recipient',
            'email' => 'buyer@example.test',
        ], $overrides));
    }

    protected function makeQuotation(?Customer $customer = null): Quotation
    {
        return app(CreateQuotation::class)->handle([
            'customer_id' => $customer?->id,
            'lines' => [
                ['product_id' => $this->product->id, 'qty' => 3, 'unit_price' => 150],
            ],
        ], $this->httpRequest());
    }

    protected function userWith(array $permissionKeys, string $name = 'Quotation User'): User
    {
        $user = $this->makeUser(['name' => $name]);
        $user->roles()->sync($this->roleWith($permissionKeys)->id);
        app(PermissionCatalog::class)->invalidate($user);

        return $user;
    }

    public function test_sending_marks_the_quotation_sent_with_a_real_outbox_event(): void
    {
        $customer = $this->makeCustomer();
        $quote = $this->makeQuotation($customer);
        $this->assertSame('draft', $quote->status);

        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertRedirect();

        $fresh = $quote->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);
        $this->assertSame('email', $fresh->sent_channel);
        $this->assertSame('buyer@example.test', $fresh->sent_to);

        $event = OutboxEvent::query()
            ->where('aggregate_type', 'quotation')
            ->where('aggregate_id', $quote->id)
            ->where('event_type', QuotationSent::class)
            ->firstOrFail();
        $this->assertSame('dispatched', $event->status);
        $this->assertSame($quote->quote_no, $event->payload['quoteNo']);
        $this->assertSame('buyer@example.test', $event->payload['to']);

        $audit = AuditEvent::query()
            ->where('action', 'sales.quotation_sent')
            ->where('entity_id', $quote->id)
            ->firstOrFail();
        $this->assertSame('email', $audit->after['sent_channel']);

        Notification::query()
            ->where('event_type', 'sales.quotation_sent')
            ->where('user_id', $this->admin->id)
            ->firstOrFail();
    }

    public function test_sent_facet_lists_only_delivered_quotations(): void
    {
        $customer = $this->makeCustomer();
        $sent = $this->makeQuotation($customer);
        $draft = $this->makeQuotation($customer);

        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $sent), ['channel' => 'email'])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('sales.quotations.index', ['status' => 'sent']))
            ->assertOk()
            ->assertSee($sent->quote_no)
            ->assertDontSee($draft->quote_no);
    }

    public function test_send_requires_its_permission_while_the_sent_facet_needs_only_view(): void
    {
        $customer = $this->makeCustomer();
        $quote = $this->makeQuotation($customer);

        $viewer = $this->userWith(['portal.erp.access', 'sales.quotations.view'], 'Quote Viewer');
        $this->actingAs($viewer)
            ->get(route('sales.quotations.index', ['status' => 'sent']))
            ->assertOk();
        $this->actingAs($viewer)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertForbidden();
        $this->assertSame('draft', $quote->fresh()->status);

        $sender = $this->userWith(
            ['portal.erp.access', 'sales.quotations.view', 'sales.quotations.send'],
            'Quote Sender',
        );
        $this->actingAs($sender)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertRedirect();
        $this->assertSame('sent', $quote->fresh()->status);
    }

    public function test_a_converted_quotation_cannot_be_sent(): void
    {
        $customer = $this->makeCustomer();
        $quote = $this->makeQuotation($customer);

        app(ConvertQuotationToOrder::class)->handle($quote->fresh(), [
            'warehouse_id' => $this->warehouse->id,
        ], $this->httpRequest());
        $this->assertSame('converted', $quote->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertRedirect()
            ->assertSessionHasErrors('quotation');

        $fresh = $quote->fresh();
        $this->assertSame('converted', $fresh->status);
        $this->assertNull($fresh->sent_at);
        $this->assertSame(
            0,
            OutboxEvent::query()
                ->where('aggregate_type', 'quotation')
                ->where('aggregate_id', $quote->id)
                ->count(),
        );
    }

    public function test_sending_without_any_customer_contact_is_refused(): void
    {
        $customer = $this->makeCustomer(['email' => null, 'phone' => null]);
        $quote = $this->makeQuotation($customer);

        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertRedirect()
            ->assertSessionHasErrors('quotation');

        $this->assertStringContainsString(
            'no customer contact',
            $this->allFlashedErrors(),
        );

        $fresh = $quote->fresh();
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->sent_at);
        $this->assertSame(
            0,
            OutboxEvent::query()
                ->where('aggregate_type', 'quotation')
                ->where('aggregate_id', $quote->id)
                ->count(),
        );
    }

    public function test_resend_records_a_second_delivery(): void
    {
        $customer = $this->makeCustomer();
        $quote = $this->makeQuotation($customer);

        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'email'])
            ->assertRedirect();
        $firstSentAt = $quote->fresh()->sent_at;

        $this->actingAs($this->admin)
            ->post(route('sales.quotations.send', $quote), ['channel' => 'sms', 'to' => '+8801700000000'])
            ->assertRedirect();

        $fresh = $quote->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertSame('sms', $fresh->sent_channel);
        $this->assertSame('+8801700000000', $fresh->sent_to);
        $this->assertTrue($fresh->sent_at->greaterThanOrEqualTo($firstSentAt));

        $this->assertSame(
            2,
            OutboxEvent::query()
                ->where('aggregate_type', 'quotation')
                ->where('aggregate_id', $quote->id)
                ->where('event_type', QuotationSent::class)
                ->count(),
        );
    }
}
