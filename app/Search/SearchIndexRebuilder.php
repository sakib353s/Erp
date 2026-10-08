<?php

namespace App\Search;

use App\Domain\Documents\Document;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Company;
use App\Domain\Foundation\Role;
use App\Domain\Foundation\User;
use App\Domain\Foundation\Warehouse;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\Supplier;
use App\Domain\People\Employee;
use App\Domain\Purchase\Models\PurchaseBill;
use App\Domain\Purchase\Models\PurchaseOrder;
use App\Domain\Sales\DeliveryChallan;
use App\Domain\Sales\Invoice;
use App\Domain\Sales\Quotation;
use App\Domain\Sales\SalesOrder;
use App\Domain\Sales\Warranty;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Safe, full rebuild of the search index from source tables only (rows 15-28
 * and 16-49). Never invents rows; deletes and recreates the company's index in
 * one transaction. Returns per-entity counts.
 *
 * **What goes in.** Two kinds of thing: the workspace itself (people, branches,
 * warehouses, roles, files) and the business records somebody is holding in
 * their hand when they reach for the search box (an invoice number off a
 * printed page, a customer's phone number, a product's barcode, a serial number
 * on a warranty card). Each row carries three things beyond its text: the
 * company, the branch it belongs to (null for a company-wide fact such as a
 * shared product or a supplier), and — the one that matters — `permission_key`,
 * the key that admits somebody to the record it points at. `SearchService`
 * applies all three at read time, so an index row is never a way around a
 * screen.
 *
 * **Why an index at all, rather than searching the tables live.** A live search
 * has to query a dozen tables on every keystroke and cannot rank across them;
 * the index is one table, one normalized blob per record, which is what makes
 * "type four characters and get the right invoice" feel instant. The cost of an
 * index is staleness, and that is paid for deliberately: the rebuild is cheap
 * (whole rows, contiguous ids) and it is scheduled hourly, so the worst case is
 * that something raised in the last few minutes is not findable yet — it is
 * still on its own list, and the screen it belongs to never lies.
 */
class SearchIndexRebuilder
{
    /**
     * @return array<string, int> entity_type => rows written
     */
    public function rebuild(?int $companyId = null): array
    {
        $companyId ??= Company::current()?->id;

        if ($companyId === null) {
            return [];
        }

        return DB::transaction(function () use ($companyId) {
            SearchIndex::query()->where('company_id', $companyId)->delete();

            $counts = [];

            $counts['user'] = $this->indexUsers($companyId);
            $counts['branch'] = $this->indexBranches($companyId);
            $counts['warehouse'] = $this->indexWarehouses($companyId);
            $counts['role'] = $this->indexRoles($companyId);
            $counts['document'] = $this->indexDocuments($companyId);

            // §16-49: the records people actually search for — a document number
            // off a printed page, a phone number, a barcode, a serial.
            $counts['customer'] = $this->indexCustomers($companyId);
            $counts['supplier'] = $this->indexSuppliers($companyId);
            $counts['product'] = $this->indexProducts($companyId);
            $counts['employee'] = $this->indexEmployees($companyId);
            $counts['invoice'] = $this->indexInvoices($companyId);
            $counts['quotation'] = $this->indexQuotations($companyId);
            $counts['order'] = $this->indexSalesOrders($companyId);
            $counts['challan'] = $this->indexChallans($companyId);
            $counts['purchase_order'] = $this->indexPurchaseOrders($companyId);
            $counts['purchase_bill'] = $this->indexPurchaseBills($companyId);
            $counts['warranty'] = $this->indexWarranties($companyId);

            return array_filter($counts, fn ($n) => $n > 0);
        });
    }

    protected function indexUsers(int $companyId): int
    {
        $count = 0;

        User::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($users) use (&$count) {
                foreach ($users as $user) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'user', 'entity_id' => $user->id],
                        [
                            'company_id' => $user->company_id,
                            'branch_id' => $user->default_branch_id,
                            'permission_key' => 'users.view',
                            'title' => $user->name,
                            'subtitle' => $user->email,
                            'excerpt' => $user->phone,
                            'url' => '/app/users/'.$user->id,
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $user->name,
                                    $user->email,
                                    $user->phone,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexBranches(int $companyId): int
    {
        $count = 0;

        Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($branches) use (&$count) {
                foreach ($branches as $branch) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'branch', 'entity_id' => $branch->id],
                        [
                            'company_id' => $branch->company_id,
                            'branch_id' => $branch->id,
                            'permission_key' => 'branches.view',
                            'title' => $branch->name,
                            'subtitle' => $branch->code,
                            'excerpt' => collect([
                                $branch->address_line1,
                                $branch->district,
                            ])->filter()->implode(', '),
                            'url' => '/app/branches/'.$branch->id,
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $branch->name,
                                    $branch->code,
                                    $branch->address_line1,
                                    $branch->district,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexWarehouses(int $companyId): int
    {
        $count = 0;

        Warehouse::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($warehouses) use (&$count) {
                foreach ($warehouses as $warehouse) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'warehouse', 'entity_id' => $warehouse->id],
                        [
                            'company_id' => $warehouse->company_id,
                            'branch_id' => $warehouse->branch_id,
                            'permission_key' => 'warehouses.view',
                            'title' => $warehouse->name,
                            'subtitle' => $warehouse->code,
                            'excerpt' => $warehouse->address,
                            'url' => '/app/warehouses/'.$warehouse->id.'/edit',
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $warehouse->name,
                                    $warehouse->code,
                                    $warehouse->address,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexRoles(int $companyId): int
    {
        $count = 0;

        Role::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($roles) use (&$count) {
                foreach ($roles as $role) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'role', 'entity_id' => $role->id],
                        [
                            'company_id' => $role->company_id,
                            'branch_id' => null,
                            'permission_key' => 'roles.view',
                            'title' => $role->name,
                            'subtitle' => $role->slug,
                            'excerpt' => $role->description,
                            'url' => '/app/roles/'.$role->id,
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $role->name,
                                    $role->slug,
                                    $role->description,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    protected function indexDocuments(int $companyId): int
    {
        $count = 0;

        Document::query()
            ->where('company_id', $companyId)
            ->orderBy('id')
            ->chunkById(200, function ($documents) use (&$count) {
                foreach ($documents as $document) {
                    SearchIndex::updateOrCreate(
                        ['entity_type' => 'document', 'entity_id' => $document->id],
                        [
                            'company_id' => $document->company_id,
                            'branch_id' => $document->branch_id,
                            'permission_key' => 'documents.view',
                            'title' => $document->original_name,
                            'subtitle' => $document->purpose ?: 'attachment',
                            'excerpt' => $document->mime_type,
                            'url' => '/app/documents',
                            'normalized' => mb_strtolower(
                                implode(' ', array_filter([
                                    $document->original_name,
                                    $document->purpose,
                                    $document->mime_type,
                                ]))
                            ),
                        ],
                    );
                    $count++;
                }
            });

        return $count;
    }

    /* ------------------------------------------------------------------ §16-49
     |
     | The business records. Each of these answers the same question in the same
     | shape: what does somebody type to find this, which permission admits them
     | to it, which branch is it in, and where does opening it go. `blob()` joins
     | the searchable fields into the one normalized column the query matches.
     |
     | Two conventions worth stating once rather than eleven times:
     |
     |  · **url** is stored root-relative (`route(..., false)`). The index is a
     |    stored artifact and the host is not part of the record — a row written
     |    while the app was on one domain must still open after the app moves,
     |    and must open on whichever domain the reader is actually using.
     |  · **branch_id** is null for anything company-wide: the product catalogue,
     |    customers, suppliers. Those are shared facts, and pretending a shared
     |    product belongs to one outlet would hide it from the other outlets that
     |    sell it.
     |
     */

    protected function indexCustomers(int $companyId): int
    {
        return $this->writeEach(
            Customer::query()->where('company_id', $companyId),
            'customer',
            fn (Customer $customer) => [
                'branch_id' => null, // a customer belongs to the company, not an outlet
                'permission_key' => 'customers.view',
                'title' => $customer->name,
                'subtitle' => trim(implode(' · ', array_filter([$customer->code, $customer->phone]))),
                'excerpt' => $customer->address_line1,
                'url' => route('customers.show', $customer, false),
                'normalized' => $this->blob($customer->name, $customer->code, $customer->phone, $customer->email, $customer->address_line1),
            ],
        );
    }

    protected function indexSuppliers(int $companyId): int
    {
        return $this->writeEach(
            Supplier::query()->where('company_id', $companyId),
            'supplier',
            fn (Supplier $supplier) => [
                'branch_id' => null,
                'permission_key' => 'suppliers.view',
                'title' => $supplier->name,
                'subtitle' => trim(implode(' · ', array_filter([$supplier->code, $supplier->phone]))),
                'excerpt' => null,
                'url' => route('suppliers.show', $supplier, false),
                'normalized' => $this->blob($supplier->name, $supplier->code, $supplier->phone, $supplier->email),
            ],
        );
    }

    protected function indexProducts(int $companyId): int
    {
        return $this->writeEach(
            Product::query()->where('company_id', $companyId),
            'product',
            fn (Product $product) => [
                'branch_id' => null, // the catalogue is shared across outlets
                'permission_key' => 'inventory.products.view',
                'title' => $product->name,
                'subtitle' => trim(implode(' · ', array_filter([$product->sku, $product->code]))),
                'excerpt' => $product->barcode,
                'url' => route('inventory.products.index', ['q' => $product->sku], false),
                // A barcode is typed at a counter and pasted from a scanner, so it
                // is the one field that must match exactly what is on the label.
                'normalized' => $this->blob($product->name, $product->sku, $product->code, $product->barcode, $product->description),
            ],
        );
    }

    protected function indexEmployees(int $companyId): int
    {
        return $this->writeEach(
            Employee::query()->where('company_id', $companyId),
            'employee',
            fn (Employee $employee) => [
                'branch_id' => $employee->branch_id,
                'permission_key' => 'employees.view',
                'title' => $employee->full_name,
                'subtitle' => trim(implode(' · ', array_filter([$employee->code, $employee->displayDesignation()]))),
                'excerpt' => $employee->phone,
                'url' => route('employees.show', $employee, false),
                'normalized' => $this->blob($employee->full_name, $employee->code, $employee->phone, $employee->email, $employee->designation),
            ],
        );
    }

    protected function indexInvoices(int $companyId): int
    {
        return $this->writeEach(
            Invoice::query()->where('company_id', $companyId)->with('customer:id,name'),
            'invoice',
            fn (Invoice $invoice) => [
                'branch_id' => $invoice->branch_id,
                'permission_key' => 'sales.invoices.view',
                'title' => $invoice->invoice_no,
                'subtitle' => trim(implode(' · ', array_filter([$invoice->customer?->name, $invoice->invoice_date?->format('d M Y')]))),
                'excerpt' => number_format((float) $invoice->grand_total, 2).' · '.$invoice->status,
                'url' => route('sales.invoices.show', $invoice, false),
                'normalized' => $this->blob($invoice->invoice_no, $invoice->customer?->name, $invoice->customer?->phone),
            ],
        );
    }

    protected function indexQuotations(int $companyId): int
    {
        return $this->writeEach(
            Quotation::query()->where('company_id', $companyId)->with('customer:id,name'),
            'quotation',
            fn (Quotation $quotation) => [
                'branch_id' => $quotation->branch_id,
                'permission_key' => 'sales.quotations.view',
                'title' => $quotation->quote_no,
                'subtitle' => trim(implode(' · ', array_filter([$quotation->customer?->name, $quotation->status]))),
                'excerpt' => null,
                // The quotation desk has no show page: it filters its own list, so
                // the hit opens that list already filtered by the number.
                'url' => route('sales.quotations.index', ['q' => $quotation->quote_no], false),
                'normalized' => $this->blob($quotation->quote_no, $quotation->customer?->name),
            ],
        );
    }

    protected function indexSalesOrders(int $companyId): int
    {
        return $this->writeEach(
            SalesOrder::query()->where('company_id', $companyId)->with('customer:id,name'),
            'order',
            fn (SalesOrder $order) => [
                'branch_id' => $order->branch_id,
                'permission_key' => 'sales.orders.view',
                'title' => $order->order_no,
                'subtitle' => trim(implode(' · ', array_filter([$order->customer?->name, $order->status]))),
                'excerpt' => $order->order_date?->format('d M Y'),
                'url' => route('sales.orders.show', $order, false),
                'normalized' => $this->blob($order->order_no, $order->customer?->name, $order->customer?->phone),
            ],
        );
    }

    protected function indexChallans(int $companyId): int
    {
        return $this->writeEach(
            DeliveryChallan::query()->where('company_id', $companyId)->with('order.customer:id,name'),
            'challan',
            fn (DeliveryChallan $challan) => [
                'branch_id' => $challan->branch_id,
                'permission_key' => 'sales.delivery.view',
                'title' => $challan->challan_no,
                'subtitle' => trim(implode(' · ', array_filter([$challan->order?->customer?->name, $challan->status]))),
                // A tracking number is what a customer reads out over the phone.
                'excerpt' => trim(implode(' · ', array_filter([$challan->courier_name, $challan->tracking_no]))),
                'url' => route('sales.delivery-challans.show', $challan, false),
                'normalized' => $this->blob($challan->challan_no, $challan->tracking_no, $challan->courier_name, $challan->order?->customer?->name),
            ],
        );
    }

    protected function indexPurchaseOrders(int $companyId): int
    {
        return $this->writeEach(
            PurchaseOrder::query()->where('company_id', $companyId)->with('supplier:id,name'),
            'purchase_order',
            fn (PurchaseOrder $order) => [
                'branch_id' => $order->branch_id,
                'permission_key' => 'purchase.orders.view',
                'title' => $order->code,
                'subtitle' => trim(implode(' · ', array_filter([$order->supplier?->name, $order->status]))),
                'excerpt' => $order->reference,
                'url' => route('purchase.orders.show', $order, false),
                'normalized' => $this->blob($order->code, $order->reference, $order->supplier?->name),
            ],
        );
    }

    protected function indexPurchaseBills(int $companyId): int
    {
        return $this->writeEach(
            PurchaseBill::query()->where('company_id', $companyId)->with('supplier:id,name'),
            'purchase_bill',
            fn (PurchaseBill $bill) => [
                'branch_id' => $bill->branch_id,
                'permission_key' => 'purchase.bills.view',
                'title' => $bill->code,
                // The supplier's own number is what is printed on the paper in the
                // file, so it is what somebody will type.
                'subtitle' => trim(implode(' · ', array_filter([$bill->supplier_bill_no, $bill->supplier?->name]))),
                'excerpt' => number_format((float) $bill->total, 2).' · '.$bill->status,
                'url' => route('purchase.bills.show', $bill, false),
                'normalized' => $this->blob($bill->code, $bill->supplier_bill_no, $bill->supplier?->name),
            ],
        );
    }

    protected function indexWarranties(int $companyId): int
    {
        return $this->writeEach(
            Warranty::query()->where('company_id', $companyId)->with(['product:id,name,sku', 'customer:id,name']),
            'warranty',
            fn (Warranty $warranty) => [
                'branch_id' => $warranty->branch_id,
                'permission_key' => 'sales.warranties.view',
                'title' => $warranty->code,
                'subtitle' => trim(implode(' · ', array_filter([$warranty->product?->name, $warranty->customer?->name]))),
                // A serial number is how a customer identifies the unit at the
                // counter, and the cover's date is what they are asking about.
                'excerpt' => trim(implode(' · ', array_filter([$warranty->serial_no, 'to '.$warranty->ends_on?->format('d M Y')]))),
                'url' => route('sales.warranties.show', $warranty, false),
                'normalized' => $this->blob($warranty->code, $warranty->serial_no, $warranty->product?->name, $warranty->product?->sku, $warranty->customer?->name, $warranty->customer?->phone),
            ],
        );
    }

    /* ------------------------------------------------------------- plumbing */

    /**
     * Walk a query in id-ordered chunks and write one index row per record.
     *
     * `chunkById` rather than `chunk`: every one of these tables is being read
     * while the application keeps writing to it, and a rebuild that skips rows
     * because the page boundary moved is a rebuild that quietly loses records.
     *
     * @param  Builder  $query
     * @param  callable  $attributes  record => [branch_id, permission_key, title, subtitle, excerpt, url, normalized]
     */
    protected function writeEach(Builder $query, string $entityType, callable $attributes): int
    {
        $count = 0;

        $query->orderBy('id')->chunkById(200, function ($records) use ($entityType, $attributes, &$count) {
            foreach ($records as $record) {
                $mapped = $attributes($record);

                $mapped['title'] = mb_substr((string) $mapped['title'], 0, 191);
                $mapped['subtitle'] = $mapped['subtitle'] === null ? null : mb_substr((string) $mapped['subtitle'], 0, 191);
                $mapped['excerpt'] = $mapped['excerpt'] === null ? null : mb_substr((string) $mapped['excerpt'], 0, 500);
                $mapped['normalized'] = mb_substr((string) $mapped['normalized'], 0, 500);

                if (! $record instanceof Model) {
                    continue;
                }

                // The index is keyed on (entity_type, entity_id), and the id is
                // what makes a rebuild idempotent: the same record written twice
                // updates its own row rather than adding a second one.
                SearchIndex::updateOrCreate(
                    ['entity_type' => $entityType, 'entity_id' => $record->getKey()],
                    [
                        'company_id' => $record->company_id,
                        ...$mapped,
                    ],
                );

                $count++;
            }
        });

        return $count;
    }

    /** The searchable text of one record, lowercased and space-joined. */
    protected function blob(?string ...$parts): string
    {
        return mb_strtolower(implode(' ', array_filter(array_map('trim', $parts), fn ($part) => $part !== '')));
    }
}
