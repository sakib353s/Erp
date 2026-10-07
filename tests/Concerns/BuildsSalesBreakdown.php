<?php

namespace Tests\Concerns;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\FiscalYear;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Actions\CreateOpeningStock;
use App\Domain\Inventory\Actions\CreateProduct;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Brand;
use App\Domain\Masters\Customer;
use App\Domain\Masters\DeliveryZone;
use App\Domain\Masters\ProductCategory;
use App\Domain\People\Employee;
use App\Domain\Sales\Actions\ConfirmOrder;
use App\Domain\Sales\Actions\CreateInvoiceFromOrder;
use App\Domain\Sales\Actions\CreateSalesOrder;
use App\Domain\Sales\Actions\IssueInvoice;
use App\Domain\Sales\Invoice;
use Carbon\Carbon;
use Database\Seeders\AccountingCoreSeeder;
use Database\Seeders\DocumentTypeSeeder;
use Database\Seeders\FoundationPermissionSeeder;
use Database\Seeders\InventoryCoreSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\SalesCoreSeeder;
use Illuminate\Http\Request;

/**
 * Shared setup for the sales breakdown report tests (02-115): a tenant
 * admin, an opened posting window anchored to the fiscal year, products
 * with optional category/brand, and real issued invoices with lines.
 */
trait BuildsSalesBreakdown
{
    protected User $admin;

    protected Warehouse $warehouse;

    protected string $fyStart;

    protected function setUpSalesBreakdown(): void
    {
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

        $fy = FiscalYear::query()
            ->where('company_id', $this->admin->company_id)
            ->where('is_current', true)
            ->firstOrFail();
        $this->fyStart = $fy->starts_on->toDateString();
    }

    /** Date N days after the fiscal year start — always inside an open posting period. */
    protected function fyDay(int $days): string
    {
        return Carbon::parse($this->fyStart)->addDays($days)->toDateString();
    }

    protected function httpRequest(): Request
    {
        $request = Request::create('/__sales-breakdown', 'POST', [], [], [], [
            'HTTP_HOST' => 'instance.test',
        ]);
        $request->setUserResolver(fn () => $this->admin);

        return $request;
    }

    protected function makeProduct(
        string $code,
        string $name,
        ?ProductCategory $category = null,
        ?Brand $brand = null,
    ): Product {
        $product = app(CreateProduct::class)->handle([
            'code' => $code,
            'sku' => $code.'-SKU',
            'name' => $name,
            'cost_method' => 'fifo',
            'standard_cost' => 100,
            'is_stocked' => true,
            'is_active' => true,
        ], $this->httpRequest());

        if ($category !== null || $brand !== null) {
            $product->update(array_filter([
                'product_category_id' => $category?->id,
                'brand_id' => $brand?->id,
            ]));
        }

        app(CreateOpeningStock::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => [['product_id' => $product->id, 'qty' => 500, 'unit_cost' => 80]],
            'idempotency_suffix' => 'bd-'.$code.'-'.uniqid(),
        ], $this->httpRequest());

        return $product->refresh();
    }

    /**
     * @param  array<int, array{product_id: int, qty: int, unit_price: float, discount?: float}>  $lines
     */
    protected function makeInvoice(string $invoiceDate, array $lines, bool $issue = true): Invoice
    {
        $order = app(CreateSalesOrder::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'lines' => $lines,
        ], $this->httpRequest());

        app(ConfirmOrder::class)->handle($order, $this->httpRequest());

        $invoice = app(CreateInvoiceFromOrder::class)->handle(
            $order->fresh(),
            ['invoice_date' => $invoiceDate, 'due_date' => $invoiceDate],
            $this->httpRequest(),
        );

        if (! $issue) {
            return $invoice;
        }

        return app(IssueInvoice::class)->handle($invoice, $this->httpRequest());
    }

    protected function makeCustomer(string $code, string $name, ?int $districtId = null): Customer
    {
        return Customer::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $name,
            'district_id' => $districtId,
            'is_active' => true,
        ]);
    }

    protected function makeEmployee(string $code, string $fullName): Employee
    {
        return Employee::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'first_name' => $fullName,
            'full_name' => $fullName,
            'is_salesperson' => true,
        ]);
    }

    protected function makeExtraBranch(string $code, string $name): Branch
    {
        return Branch::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /** A delivery zone with the given districts attached (02-117 by-zone). */
    protected function makeZone(string $code, string $name, array $districtIds): DeliveryZone
    {
        $zone = DeliveryZone::query()->create([
            'company_id' => $this->admin->company_id,
            'code' => $code,
            'name' => $name,
            'is_active' => true,
        ]);

        $zone->districts()->attach($districtIds);

        return $zone->refresh();
    }

    /** Attribute the invoice to a customer / salesperson / other branch for dim tests. */
    protected function tagInvoice(Invoice $invoice, array $attributes): Invoice
    {
        $invoice->forceFill($attributes)->save();

        return $invoice->refresh();
    }
}
