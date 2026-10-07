<?php

namespace App\Http\Controllers;

use App\Domain\Customers\Models\CustomerAddress;
use App\Domain\Customers\Models\CustomerContact;
use App\Domain\Customers\Models\CustomerFeedback;
use App\Domain\Customers\Models\CustomerReferral;
use App\Domain\Customers\Models\CustomerWishlist;
use App\Domain\Customers\Queries\CustomerQuery;
use App\Domain\Customers\Services\CustomerService;
use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Masters\CustomerGroup;
use App\Domain\Masters\District;
use App\Domain\Masters\Upazila;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CRM / customer module (§05).
 *
 * Reads go through CustomerQuery (ledger-derived dues, bucket ageing); writes
 * go through CustomerService (uniqueness, credit history, blacklist reason).
 * The controller itself holds no business rules.
 */
class CustomerController extends Controller
{
    public function __construct(
        protected CustomerQuery $query,
        protected CustomerService $service,
    ) {}

    /* -------------------------------------------------------------- list */

    public function index(Request $request): View
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'group' => $request->query('group'),
            'status' => $request->query('status'),
            'district' => $request->query('district'),
            'sort' => $request->query('sort'),
        ];

        $customers = $this->query->paginate($filters);
        $receivables = $this->query->receivableByCustomer($customers->pluck('id'));

        return view('customers.index', [
            'customers' => $customers,
            'receivables' => $receivables,
            'filters' => $filters,
            'groups' => CustomerGroup::query()->orderBy('name')->get(['id', 'name']),
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
            'summary' => [
                'total' => $customers->total(),
                'due' => round(array_sum(array_column($receivables, 'due')), 2),
                'overdue' => round(array_sum(array_column($receivables, 'overdue')), 2),
                'over_limit' => count($this->query->overLimitIds()),
            ],
        ]);
    }

    /* ------------------------------------------------------------ create */

    public function create(): View
    {
        return view('customers.form', [
            'customer' => new Customer(['type' => 'individual', 'is_active' => true, 'opening_balance_type' => 'due']),
            'mode' => 'create',
            ...$this->formData(),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        try {
            $customer = $this->service->create($request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        return redirect()
            ->route('customers.show', $customer)
            ->with('status', "Customer {$customer->name} ({$customer->code}) created.");
    }

    /* -------------------------------------------------------------- read */

    public function show(Customer $customer): View
    {
        $customer->load(['group', 'district', 'addresses.district', 'contacts', 'wishlist.product']);

        $receivable = $this->query->receivableByCustomer([$customer->id])[$customer->id] ?? null;

        return view('customers.show', [
            'customer' => $customer,
            'receivable' => $receivable,
            'documents' => $this->query->recentDocuments($customer),
            'openInvoices' => $this->query->openInvoices($customer),
            'feedback' => $customer->feedback()->latest()->limit(10)->get(),
            'nps' => $this->npsFor($customer),
            'creditHistory' => DB::table('customer_credit_history')
                ->where('customer_id', $customer->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
            'addresses' => $customer->addresses,
            'contacts' => $customer->contacts,
            'referrals' => $customer->referrals()->latest()->limit(10)->get(),
            'wishlist' => $customer->wishlist,
            'products' => Product::query()->orderBy('name')->limit(200)->get(['id', 'name', 'sku']),
            'districts' => District::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', [
            'customer' => $customer,
            'mode' => 'edit',
            ...$this->formData(),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        try {
            $this->service->update($customer, $request->validated(), $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    /* ------------------------------------------------------------ ledger */

    public function ledger(Request $request, Customer $customer): View
    {
        $range = [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        return view('customers.ledger', [
            'customer' => $customer,
            'ledger' => $this->query->ledger($customer, $range),
            'range' => $range,
        ]);
    }

    public function statement(Request $request, Customer $customer): View
    {
        $range = [
            'from' => $request->query('from') ?: now()->startOfMonth()->toDateString(),
            'to' => $request->query('to') ?: now()->toDateString(),
        ];

        return view('customers.statement', [
            'customer' => $customer->load('addresses', 'district'),
            'ledger' => $this->query->ledger($customer, $range),
            'range' => $range,
        ]);
    }

    /* --------------------------------------------------------------- due */

    public function due(Request $request): View
    {
        $bucket = (string) $request->query('bucket', 'all');
        $buckets = $this->query->dueBuckets();

        $rows = DB::table('invoices')
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->whereIn('invoices.status', ['issued', 'partial'])
            ->whereRaw('invoices.grand_total > invoices.paid_amount')
            ->orderBy('invoices.due_date')
            ->get([
                'invoices.id', 'invoices.invoice_no', 'invoices.due_date', 'invoices.invoice_date',
                'invoices.grand_total', 'invoices.paid_amount', 'customers.id as customer_id',
                'customers.name as customer_name', 'customers.code as customer_code',
            ])
            ->map(function ($row) {
                $row->due = round((float) $row->grand_total - (float) $row->paid_amount, 2);
                $row->days_overdue = $row->due_date
                    ? max(0, (int) now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($row->due_date)->startOfDay(), false) * -1)
                    : 0;
                $row->bucket = CustomerQuery::bucketFor($row->due_date);

                return $row;
            });

        if ($bucket !== 'all' && in_array($bucket, CustomerQuery::BUCKETS, true)) {
            $rows = $rows->where('bucket', $bucket)->values();
        }

        return view('customers.due', [
            'rows' => $rows,
            'buckets' => $buckets,
            'bucket' => $bucket,
            'total' => round($rows->sum('due'), 2),
        ]);
    }

    /** AJAX: open invoices for the money-receipt flow (05-10b). */
    public function openInvoices(Customer $customer): JsonResponse
    {
        // Authorization is the route middleware (customers.view): the endpoint
        // only ever exposes the customer the caller already opened.
        return response()->json([
            'customer' => ['id' => $customer->id, 'name' => $customer->name, 'code' => $customer->code],
            'credit' => $this->service->creditProjection($customer, 0),
            'invoices' => $this->query->openInvoices($customer)->map(fn ($row) => [
                'id' => $row->id,
                'invoice_no' => $row->invoice_no,
                'invoice_date' => $row->invoice_date,
                'due_date' => $row->due_date,
                'total' => round((float) $row->grand_total, 2),
                'paid' => round((float) $row->paid_amount, 2),
                'due' => $row->due,
                'bucket' => $row->bucket,
            ])->all(),
        ]);
    }

    /* ----------------------------------------------------------- credit */

    public function updateCreditLimit(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'limit' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->setCreditLimit($customer, $data, $request->user()?->id);
        } catch (RuntimeException $e) {
            return back()->withErrors(['limit' => $e->getMessage()]);
        }

        return back()->with('status', "Credit limit for {$customer->name} is now ৳ ".number_format((float) $data['limit'], 2).'.');
    }

    public function blacklist(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'blacklisted' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->service->setBlacklist(
                $customer,
                (bool) $data['blacklisted'],
                $data['reason'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', $data['blacklisted']
            ? "{$customer->name} is blacklisted. New documents will be refused."
            : "{$customer->name} restored — documents can be raised again.");
    }

    /* --------------------------------------------------- addresses / contacts */

    public function storeAddress(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:128'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address_line1' => ['required', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'upazila_id' => ['nullable', 'integer', 'exists:upazilas,id'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $data['company_id'] = $customer->company_id;
        $data['customer_id'] = $customer->id;

        $address = CustomerAddress::create($data);

        if (! empty($data['is_default'])) {
            $this->service->makeDefaultAddress($address);
        }

        return back()->with('status', 'Address added.');
    }

    public function destroyAddress(Customer $customer, CustomerAddress $address): RedirectResponse
    {
        abort_unless($address->customer_id === $customer->id, 404);

        $address->delete();

        return back()->with('status', 'Address removed.');
    }

    public function storeContact(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'designation' => ['nullable', 'string', 'max:96'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:191'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $contact = CustomerContact::create([
            ...$data,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
        ]);

        if (! empty($data['is_primary'])) {
            $this->service->makePrimaryContact($contact);
        }

        return back()->with('status', 'Contact added.');
    }

    public function destroyContact(Customer $customer, CustomerContact $contact): RedirectResponse
    {
        abort_unless($contact->customer_id === $customer->id, 404);

        $contact->delete();

        return back()->with('status', 'Contact removed.');
    }

    /* ------------------------------------------- feedback / referrals / wishlist */

    public function storeFeedback(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:0', 'max:10'],
            'channel' => ['required', 'in:'.implode(',', CustomerFeedback::CHANNELS)],
            'category' => ['nullable', 'in:'.implode(',', CustomerFeedback::CATEGORIES)],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        CustomerFeedback::create([
            ...$data,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'recorded_by' => $request->user()?->id,
        ]);

        return back()->with('status', 'Feedback recorded.');
    }

    public function storeReferral(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'referred_name' => ['required', 'string', 'max:191'],
            'referred_phone' => ['nullable', 'string', 'max:32'],
            'status' => ['required', 'in:'.implode(',', CustomerReferral::STATUSES)],
            'reward_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        CustomerReferral::create([
            ...$data,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
        ]);

        return back()->with('status', 'Referral logged.');
    }

    public function storeWishlist(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        CustomerWishlist::updateOrCreate(
            ['customer_id' => $customer->id, 'product_id' => $data['product_id']],
            ['company_id' => $customer->company_id, 'note' => $data['note'] ?? null],
        );

        return back()->with('status', 'Added to the wishlist.');
    }

    public function destroyWishlist(Customer $customer, CustomerWishlist $wishlist): RedirectResponse
    {
        abort_unless($wishlist->customer_id === $customer->id, 404);

        $wishlist->delete();

        return back()->with('status', 'Removed from the wishlist.');
    }

    /* ------------------------------------------------------------ groups */

    public function groups(): View
    {
        $groups = CustomerGroup::query()
            ->withCount('customers')
            ->orderBy('name')
            ->get();

        return view('customers.groups', [
            'groups' => $groups,
            'rules' => DB::table('pricing_rules')
                ->whereNotNull('customer_group_id')
                ->selectRaw('customer_group_id, COUNT(*) as rules')
                ->groupBy('customer_group_id')
                ->pluck('rules', 'customer_group_id'),
        ]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $exists = CustomerGroup::query()
            ->where('code', $data['code'])
            ->exists();

        if ($exists) {
            return back()->withInput()->withErrors(['code' => 'That group code already exists.']);
        }

        CustomerGroup::create([...$data, 'is_active' => true]);

        return back()->with('status', "Group {$data['name']} created.");
    }

    /* ------------------------------------------------------------ export */

    public function export(Request $request): StreamedResponse
    {
        $filters = [
            'q' => trim((string) $request->query('q')),
            'group' => $request->query('group'),
            'status' => $request->query('status'),
            'district' => $request->query('district'),
        ];

        $customers = $this->query->paginate($filters, perPage: 5000);
        $receivables = $this->query->receivableByCustomer($customers->pluck('id'));

        return response()->streamDownload(function () use ($customers, $receivables) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Name', 'Type', 'Phone', 'Email', 'Group', 'District', 'Credit limit', 'Credit days', 'Invoiced', 'Paid', 'Due', 'Overdue', 'Status']);

            foreach ($customers as $customer) {
                $money = $receivables[$customer->id] ?? ['invoiced' => 0, 'paid' => 0, 'due' => 0, 'overdue' => 0];

                fputcsv($out, [
                    $customer->code,
                    $customer->name,
                    $customer->type,
                    $customer->phone,
                    $customer->email,
                    $customer->group?->name,
                    $customer->district?->name,
                    number_format((float) $customer->credit_limit, 2, '.', ''),
                    $customer->credit_days,
                    number_format($money['invoiced'], 2, '.', ''),
                    number_format($money['paid'], 2, '.', ''),
                    number_format($money['due'], 2, '.', ''),
                    number_format($money['overdue'], 2, '.', ''),
                    $customer->status(),
                ]);
            }

            fclose($out);
        }, 'customers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /* ------------------------------------------------------------- helpers */

    /** @return array<string, mixed> */
    protected function formData(): array
    {
        return [
            'groups' => CustomerGroup::query()->orderBy('name')->get(['id', 'name']),
            'districts' => District::query()->orderBy('name')->get(['id', 'name', 'division']),
            'types' => Customer::TYPES,
            'segments' => Customer::SEGMENTS,
        ];
    }

    /** NPS over the recorded samples — promoters minus detractors, in percent. */
    protected function npsFor(Customer $customer): array
    {
        $scores = $customer->feedback()->pluck('score');

        if ($scores->isEmpty()) {
            return ['samples' => 0, 'score' => null];
        }

        $promoters = $scores->filter(fn ($s) => $s >= 9)->count();
        $detractors = $scores->filter(fn ($s) => $s <= 6)->count();

        return [
            'samples' => $scores->count(),
            'score' => (int) round((($promoters - $detractors) / $scores->count()) * 100),
        ];
    }
}
