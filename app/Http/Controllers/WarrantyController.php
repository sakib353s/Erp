<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Product;
use App\Domain\Masters\Customer;
use App\Domain\Sales\ProductWarranty;
use App\Domain\Sales\Services\WarrantyService;
use App\Domain\Sales\Warranty;
use App\Domain\Sales\WarrantyClaim;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * §16-16 / §16-17 / §16-18 — the warranty desk.
 *
 * Three screens, because a warranty is three questions asked at three different
 * times:
 *
 *  · **policies** — what does the company promise about this product, and for
 *    how long? Asked once, when the product is set up.
 *  · **register** — what did we actually promise, to whom, and until when?
 *    Asked at the counter while the customer is standing there.
 *  · **claims** — what went wrong and what was done. Asked when the customer
 *    comes back.
 *
 * The register is the only place the three meet, and its default filter is the
 * live covers: it is read at a counter, where "is this still in warranty" is the
 * only question that matters.
 *
 * Nothing here computes cover. `WarrantyService` owns the rules — the dates are
 * fixed at activation, the once-only key is enforced there, and every write
 * leaves an audit row — and this class only reads a request and puts the
 * refusal back on the page.
 */
class WarrantyController extends Controller
{
    public function __construct(protected WarrantyService $warranties) {}

    /* ------------------------------------------------------------ the desk */

    /** The register: what was promised, with the clock read at this moment. */
    public function index(Request $request): View
    {
        $state = (string) $request->query('state', 'live');

        $query = Warranty::query()
            ->where('company_id', $this->warranties->companyId())
            ->with(['product', 'customer', 'invoice']);

        match ($state) {
            'live' => $query->live(),
            'expiring' => $query->expiringWithin(Warranty::EXPIRING_DAYS),
            'expired' => $query->expired(),
            'claimed' => $query->where('status', Warranty::STATUS_CLAIMED),
            'voided' => $query->where('status', Warranty::STATUS_VOIDED),
            default => $query->live(),
        };

        if ($request->filled('product')) {
            $query->where('product_id', (int) $request->query('product'));
        }

        if ($request->filled('customer')) {
            $query->where('customer_id', (int) $request->query('customer'));
        }

        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->query('q')).'%';

            $query->where(function ($inner) use ($term) {
                $inner->where('code', 'like', $term)
                    ->orWhere('serial_no', 'like', $term)
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $term)->orWhere('code', 'like', $term))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term));
            });
        }

        $warranties = $query
            // Expired covers are read newest-first — the one that ran out
            // yesterday is the one being argued about at the counter.
            ->orderBy('ends_on', $state === 'expired' ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('sales.warranties.index', [
            'warranties' => $warranties,
            'state' => $state,
            'summary' => $this->warranties->summary(),
            'expiring' => $this->warranties->expiringSoon(),
            'canManage' => $request->user()->can('sales.warranties.manage'),
            'products' => Product::query()->where('company_id', $this->warranties->companyId())->orderBy('name')->limit(400)->get(['id', 'name', 'code']),
            'customers' => Customer::query()->where('company_id', $this->warranties->companyId())->orderBy('name')->limit(400)->get(['id', 'name']),
        ]);
    }

    /** One cover: the promise, the dates, and everything claimed against it. */
    public function show(Request $request, Warranty $warranty): View
    {
        $this->authorizeRow($warranty);

        $warranty->load(['product', 'customer', 'invoice', 'challan', 'activator', 'claims.resolver', 'claims.raiser']);

        return view('sales.warranties.show', [
            'warranty' => $warranty,
            'policy' => $this->warranties->policyFor((int) $warranty->product_id),
            'canManage' => $request->user()->can('sales.warranties.manage'),
            'claimStates' => WarrantyClaim::STATUSES,
            'resolutions' => WarrantyClaim::RESOLUTIONS,
        ]);
    }

    /* ------------------------------------------------------------ policies */

    /** What the company promises per product — the policy desk. */
    public function policies(Request $request): View
    {
        $companyId = $this->warranties->companyId();

        $policies = ProductWarranty::query()
            ->where('company_id', $companyId)
            ->with(['product', 'updater'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.trim((string) $request->query('q')).'%';

                $query->whereHas('product', fn ($product) => $product->where('name', 'like', $term)->orWhere('code', 'like', $term));
            })
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        $covered = ProductWarranty::query()->where('company_id', $companyId)->where('is_active', true)->count();
        $stocked = Product::query()->where('company_id', $companyId)->where('is_stocked', true)->count();
        $withoutPolicy = Product::query()
            ->where('company_id', $companyId)
            ->where('is_stocked', true)
            ->whereDoesntHave('warrantyPolicy')
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'code']);

        // The picker lists every stocked product, because the same control both
        // sets a first policy and replaces an existing one — a list of only the
        // uncovered products could not do the second job.
        $choices = Product::query()
            ->where('company_id', $companyId)
            ->where('is_stocked', true)
            ->with('warrantyPolicy:id,product_id,months')
            ->orderBy('name')
            ->limit(400)
            ->get(['id', 'name', 'code']);

        return view('sales.warranties.policies', [
            'policies' => $policies,
            'kinds' => ProductWarranty::KINDS,
            'maxMonths' => ProductWarranty::MAX_MONTHS,
            'covered' => $covered,
            'stocked' => $stocked,
            'choices' => $choices,
            'withoutPolicy' => $withoutPolicy,
            'withoutPolicyCount' => Product::query()
                ->where('company_id', $companyId)
                ->where('is_stocked', true)
                ->whereDoesntHave('warrantyPolicy')
                ->count(),
            'canManage' => $request->user()->can('sales.warranties.manage'),
        ]);
    }

    public function storePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'months' => ['required', 'integer', 'min:1', 'max:'.ProductWarranty::MAX_MONTHS],
            'kind' => ['required', Rule::in(array_keys(ProductWarranty::KINDS))],
            'covers_parts' => ['nullable', 'boolean'],
            'covers_labour' => ['nullable', 'boolean'],
            'terms' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::query()->findOrFail((int) $data['product_id']);
        $this->guardProduct($product);

        $policy = $this->warranties->savePolicy($product, $data, $request->user());

        return redirect()
            ->route('sales.warranties.policies')
            ->with('status', "{$product->name}: {$policy->months}-month {$policy->kindLabel()} recorded.");
    }

    /* ------------------------------------------------- registration by hand */

    public function create(Request $request): View
    {
        return view('sales.warranties.form', [
            'products' => Product::query()
                ->where('company_id', $this->warranties->companyId())
                ->orderBy('name')
                ->limit(400)
                ->get(['id', 'name', 'code']),
            'customers' => Customer::query()
                ->where('company_id', $this->warranties->companyId())
                ->orderBy('name')
                ->limit(400)
                ->get(['id', 'name']),
            'preselected' => $request->query('product') !== null ? (int) $request->query('product') : null,
            'today' => Carbon::now()->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'serial_no' => ['nullable', 'string', 'max:64'],
            'qty' => ['required', 'numeric', 'gt:0'],
            'months' => ['nullable', 'integer', 'min:1', 'max:'.ProductWarranty::MAX_MONTHS],
            'starts_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $product = Product::query()->findOrFail((int) $data['product_id']);
        $this->guardProduct($product);

        try {
            $warranty = $this->warranties->activateManually(
                $product,
                $data + ['product_id' => $product->id],
                $request->user(),
            );
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('sales.warranties.show', $warranty)
            ->with('status', "{$warranty->code} registered — covered to {$warranty->ends_on?->format('d M Y')}.");
    }

    /* --------------------------------------------------------------- claims */

    /** The claim queue. */
    public function claims(Request $request): View
    {
        $status = (string) $request->query('status', 'working');

        $query = WarrantyClaim::query()
            ->where('company_id', $this->warranties->companyId())
            ->with(['warranty.product', 'warranty.customer', 'resolver']);

        match ($status) {
            'working' => $query->working(),
            'completed' => $query->completed(),
            'rejected' => $query->where('status', 'rejected'),
            'all' => null,
            default => $query->where('status', $status),
        };

        if ($request->filled('q')) {
            $term = '%'.trim((string) $request->query('q')).'%';

            $query->where(function ($inner) use ($term) {
                $inner->where('code', 'like', $term)
                    ->orWhere('fault', 'like', $term)
                    ->orWhereHas('warranty', fn ($w) => $w->where('code', 'like', $term)->orWhere('serial_no', 'like', $term)
                        ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $term)));
            });
        }

        return view('sales.warranties.claims', [
            'claims' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'states' => WarrantyClaim::STATUSES,
            'summary' => $this->warranties->summary(),
            'canManage' => $request->user()->can('sales.warranties.manage'),
        ]);
    }

    public function storeClaim(Request $request, Warranty $warranty): RedirectResponse
    {
        $this->authorizeRow($warranty);

        $data = $request->validate([
            'fault' => ['required', 'string', 'min:4', 'max:500'],
            'reported_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            $claim = $this->warranties->raiseClaim($warranty, $data, $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->route('sales.warranties.claims')
            ->with('status', "{$claim->code} raised against {$warranty->code}.");
    }

    public function decideClaim(Request $request, WarrantyClaim $claim): RedirectResponse
    {
        $this->authorizeRow($claim);

        $data = $request->validate([
            'status' => ['required', 'string'],
            'resolution' => ['nullable', 'string'],
            'resolution_notes' => ['nullable', 'string', 'max:500'],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $decided = $this->warranties->decideClaim($claim, $data, $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return back()->with('status', "{$decided->code} is now {$decided->statusLabel()}.");
    }

    /* ---------------------------------------------------------------- void */

    public function void(Request $request, Warranty $warranty): RedirectResponse
    {
        $this->authorizeRow($warranty);

        $data = $request->validate([
            'void_reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        try {
            $this->warranties->void($warranty, $data['void_reason'], $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return back()->with('status', "{$warranty->code} withdrawn from cover.");
    }

    /* ------------------------------------------------------------ plumbing */

    /** A row of another company is not a 404 — it never happened. */
    protected function authorizeRow(Warranty|WarrantyClaim $row): void
    {
        if ((int) $row->company_id !== $this->warranties->companyId()) {
            abort(404);
        }
    }

    /** Products are only editable within the tenant that owns them. */
    protected function guardProduct(Product $product): void
    {
        if ((int) $product->company_id !== $this->warranties->companyId()) {
            throw ValidationException::withMessages(['product_id' => 'That product belongs to another company.']);
        }
    }
}
