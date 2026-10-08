<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\JournalLine;
use App\Domain\Business\AssetRegistry;
use App\Domain\Business\BusinessAsset;
use App\Domain\Business\BusinessRecord;
use App\Domain\Business\Services\AssetService;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\User;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * §12-14 — the asset register: what the company owns, where it is, what it is
 * worth, and what it costs to run.
 *
 * The menu names four screens here and they are four views of one register:
 * **asset register** (everything), **vehicle management** (the vehicles, with
 * their papers and what they cost per kilometre), **equipment** (the machines,
 * the computers, the fit-out) and the **vehicle trip log** (where each vehicle
 * has been and what the run cost). There is one controller because they are one
 * register — build them apart and the truck nobody can find will be the truck
 * that is missing from the vehicle list.
 *
 * Three rules hold across every page:
 *
 *  · **another company's asset is a 404**, and one on a branch this person cannot
 *    see is a 404 too — not a 403, because the register should not confirm that
 *    something exists there;
 *  · **the money is derived, never typed** — book value is cost less the
 *    depreciation that has actually been posted, and nothing on these pages can
 *    move `accumulated_depreciation` except a real journal entry;
 *  · **reading is office work, writing is not** — everybody may see where the
 *    laptop is; registering, moving, capitalising, depreciating and disposing
 *    are `business.assets.manage`, because those change the books.
 */
class AssetController extends Controller
{
    public function __construct(
        protected AssetService $assets,
        protected AssetRegistry $registry,
    ) {}

    /** The register itself: every kind of asset, on one page. */
    public function index(Request $request): View
    {
        $person = $request->user();

        $filters = [
            'shelf' => 'register',
            'category' => $request->string('category')->toString() ?: null,
            'status' => $request->string('status')->toString(),
            'q' => $request->string('q')->toString(),
            'custodian_id' => $request->query('custodian_id'),
            'branch_id' => $request->query('branch_id'),
            'disposed' => $request->string('status')->toString() === 'disposed',
        ];

        return view('business.assets.index', [
            'registry' => $this->registry,
            'assets' => $this->assets->register($filters),
            'filters' => $filters,
            'summary' => $this->assets->summary(null),
            'shelves' => $this->assets->shelfCounts(),
            'branches' => $this->branchOptions($person),
            'people' => $this->people($person),
            'dueCount' => $this->assets->dueForDepreciation()->count(),
            'canManage' => $this->manages($person),
        ]);
    }

    /** Vehicle management: the vehicles, their papers and what they cost to run. */
    public function vehicles(Request $request): View
    {
        $person = $request->user();

        $filters = [
            'shelf' => 'vehicles',
            'category' => null,
            'status' => $request->string('status')->toString(),
            'q' => $request->string('q')->toString(),
            'custodian_id' => null,
            'branch_id' => $request->query('branch_id'),
            'disposed' => false,
        ];

        $from = now()->startOfMonth();
        $to = now()->endOfMonth();

        $vehicles = $this->assets->register($filters);

        return view('business.assets.vehicles', [
            'registry' => $this->registry,
            'vehicles' => $vehicles,
            'filters' => $filters,
            'summary' => $this->assets->summary('vehicles'),
            'costs' => $this->assets->vehicleCosts(null, $from, $to)->keyBy('asset.id'),
            'periodLabel' => $from->format('F Y'),
            'papers' => $this->papers($vehicles->pluck('id')->all()),
            'branches' => $this->branchOptions($person),
            'canManage' => $this->manages($person),
        ]);
    }

    /** Equipment: the machines, the computers and the fit-out. */
    public function equipment(Request $request): View
    {
        $person = $request->user();

        $filters = [
            'shelf' => 'equipment',
            'category' => $request->string('category')->toString() ?: null,
            'status' => $request->string('status')->toString(),
            'q' => $request->string('q')->toString(),
            'custodian_id' => $request->query('custodian_id'),
            'branch_id' => $request->query('branch_id'),
            'disposed' => false,
        ];

        return view('business.assets.equipment', [
            'registry' => $this->registry,
            'equipment' => $this->assets->register($filters),
            'filters' => $filters,
            'summary' => $this->assets->summary('equipment'),
            'branches' => $this->branchOptions($person),
            'people' => $this->people($person),
            'canManage' => $this->manages($person),
        ]);
    }

    /** The trip log: every run a vehicle has made, and what it cost. */
    public function trips(Request $request): View
    {
        $person = $request->user();

        $filters = [
            'asset_id' => $request->query('asset_id'),
            'from' => $request->query('from') ?: now()->startOfMonth()->toDateString(),
            'to' => $request->query('to') ?: now()->toDateString(),
            'q' => $request->string('q')->toString(),
        ];

        $trips = $this->assets->trips($filters);

        return view('business.assets.trips', [
            'trips' => $trips,
            'filters' => $filters,
            'vehicles' => $this->vehicleOptions($person),
            'people' => $this->people($person),
            'totals' => [
                'trips' => $trips->total(),
                'distance' => round((float) $trips->getCollection()->sum('distance_km'), 2),
                'fuel' => round((float) $trips->getCollection()->sum('fuel_cost'), 2),
                'other' => round((float) $trips->getCollection()->sum('other_cost'), 2),
                'unexpensed' => $trips->getCollection()->filter(fn ($trip) => ! $trip->isExpensed())->count(),
            ],
            'canManage' => $this->manages($person),
        ]);
    }

    /**
     * The depreciation desk: what is due, what has been posted, and the run.
     *
     * The list is the point. A company does not find out at year end that it
     * forgot eleven months of depreciation — this page says so in the first line.
     */
    public function depreciation(Request $request): View
    {
        $person = $request->user();

        $due = $this->assets->dueForDepreciation();

        $depreciating = $this->assets->visible()->depreciable()->live()->with(['branch'])
            ->orderBy('last_depreciated_on')->orderBy('name')->get();

        $waiting = $this->assets->visible()->live()
            ->whereNotNull('acquisition_cost')
            ->where('acquisition_cost', '>', 0)
            ->whereNull('capitalised_at')
            ->orderBy('name')->get();

        $unplanned = $this->assets->visible()->live()
            ->whereNotNull('acquisition_cost')
            ->where('acquisition_cost', '>', 0)
            ->whereNotNull('capitalised_at')
            ->where(fn ($q) => $q->whereNull('useful_life_months')->orWhere('depreciation_method', '!=', BusinessAsset::METHOD_STRAIGHT_LINE))
            ->orderBy('name')->get();

        $posted = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.source_type', 'business_asset')
            ->where('je.journal_type', 'depreciation')
            ->where('jl.dc', JournalLine::DEBIT)
            ->selectRaw('COUNT(DISTINCT je.id) as entries, COALESCE(SUM(jl.amount), 0) as total')
            ->first();

        return view('business.assets.depreciation', [
            'registry' => $this->registry,
            'due' => $due,
            'depreciating' => $depreciating,
            'waiting' => $waiting,
            'unplanned' => $unplanned,
            'summary' => $this->assets->summary(null),
            'postedTotal' => round((float) ($posted->total ?? 0), 2),
            'postedEntries' => (int) ($posted->entries ?? 0),
            'charge' => round((float) $due->sum(fn (BusinessAsset $asset) => min($asset->monthlyDepreciation(), $asset->remainingDepreciable())), 2),
            'canManage' => $this->manages($person),
        ]);
    }

    /** What was written off, why, and what came back. */
    public function disposal(Request $request): View
    {
        $person = $request->user();

        $disposed = $this->assets->visible()
            ->disposed()
            ->with(['branch', 'custodian', 'disposedBy'])
            ->orderByDesc('disposed_on')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $all = $this->assets->visible()->disposed();

        return view('business.assets.disposal', [
            'registry' => $this->registry,
            'disposed' => $disposed,
            'summary' => [
                'count' => (clone $all)->count(),
                'proceeds' => round((float) (clone $all)->sum('disposal_proceeds'), 2),
                'cost' => round((float) (clone $all)->sum('acquisition_cost'), 2),
                'accumulated' => round((float) (clone $all)->sum('accumulated_depreciation'), 2),
                'this_year' => (clone $all)->whereYear('disposed_on', now()->year)->count(),
                'this_year_proceeds' => round((float) (clone $all)->whereYear('disposed_on', now()->year)->sum('disposal_proceeds'), 2),
            ],
            'canManage' => $this->manages($person),
        ]);
    }

    /** One asset's own page: what it is, what it is worth, where it has been. */
    public function show(Request $request, BusinessAsset $asset): View
    {
        $person = $request->user();
        $this->guard($asset, $person);

        $asset->load(['branch', 'custodian', 'driver', 'creator', 'events' => fn ($q) => $q->orderByDesc('happened_on')->orderByDesc('id')]);

        $records = BusinessRecord::query()
            ->where('business_asset_id', $asset->id)
            ->orderBy('title')
            ->get();

        $linkable = collect();

        if ($this->manages($person)) {
            $linkable = BusinessRecord::query()
                ->where('company_id', $asset->company_id)
                ->whereNull('business_asset_id')
                ->where('status', BusinessRecord::STATUS_ACTIVE)
                ->orderBy('title')
                ->limit(200)
                ->get(['id', 'title', 'kind']);
        }

        return view('business.assets.show', [
            'registry' => $this->registry,
            'asset' => $asset,
            'events' => $asset->events,
            'schedule' => $asset->schedule(120),
            'trips' => $asset->trips()->with(['driver', 'expense'])->orderByDesc('trip_date')->orderByDesc('id')->limit(25)->get(),
            'tripTotals' => [
                'trips' => $asset->trips()->count(),
                'distance' => round((float) $asset->trips()->sum('distance_km'), 2),
                'fuel' => round((float) $asset->trips()->sum('fuel_cost'), 2),
                'other' => round((float) $asset->trips()->sum('other_cost'), 2),
            ],
            'papers' => $records,
            'linkable' => $linkable,
            'drivers' => $this->people($person),
            'branches' => $this->branchOptions($person),
            'people' => $this->people($person),
            'canManage' => $this->manages($person),
        ]);
    }

    /**
     * Add asset: what kind of thing is it, and then what it is.
     *
     * The category is asked first because it decides what the rest of the form
     * asks — a van needs plates and a driver, a laptop does not — and because the
     * default life is a property of the kind of thing, not of the company. A GET
     * step rather than a scripted toggle: the page a person lands on is the page
     * their server drew, and it works with the keyboard, without JavaScript and
     * after a refresh.
     */
    public function create(Request $request): View
    {
        $person = $request->user();
        $category = $request->string('category')->toString() ?: null;

        return view('business.assets.create', [
            'registry' => $this->registry,
            'category' => $category !== null && $this->registry->has($category) ? $category : null,
            'branches' => $this->branchOptions($person),
            'people' => $this->people($person),
            'nextCode' => $this->assets->nextCode((int) $person->company_id),
            'canManage' => $this->manages($person),
        ]);
    }

    public function store(StoreAssetRequest $request): RedirectResponse
    {
        $asset = $this->assets->create($request->assetData(), $request->user());

        return redirect()
            ->route('assets.show', $asset)
            ->with('status', $asset->describe().' is on the register.'.(
                $asset->isDepreciable() ? ' The first depreciation charge falls due '.$asset->nextDepreciationOn().'.' : ''
            ));
    }

    public function update(UpdateAssetRequest $request, BusinessAsset $asset): RedirectResponse
    {
        $this->guard($asset, $request->user());

        $this->assets->update($asset, $request->assetData(), $request->user());

        return back()->with('status', $asset->describe().' updated.');
    }

    /**
     * Set how this asset turns into expense — or that it does not.
     *
     * Nothing is posted here: the policy says what *will* happen each month, the
     * depreciation run does it, and keeping the two apart is what makes the
     * schedule safe to change halfway through a life.
     */
    public function setDepreciation(Request $request, BusinessAsset $asset): RedirectResponse
    {
        $person = $request->user();
        $this->guard($asset, $person);

        $data = $request->validate([
            'method' => ['required', 'string', 'in:'.implode(',', array_keys(AssetRegistry::METHODS))],
            'useful_life_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'depreciation_starts_on' => ['nullable', 'date'],
        ]);

        $this->assets->setDepreciation($asset, $data, $person);

        $asset->refresh();

        return back()->with('status', $asset->isDepreciable()
            ? $asset->describe().' will depreciate ৳'.number_format($asset->monthlyDepreciation(), 2).' a month for '.$asset->useful_life_months.' month(s).'
            : $asset->describe().' is not being depreciated.');
    }

    /** Its cost is in the books — the gate depreciation waits behind. */
    public function capitalise(Request $request, BusinessAsset $asset): RedirectResponse
    {
        $person = $request->user();
        $this->guard($asset, $person);

        $data = $request->validate(['capitalised_on' => ['nullable', 'date', 'before_or_equal:today']]);

        $this->assets->capitalise($asset, $person, $data['capitalised_on'] ?? null);

        return back()->with('status', $asset->describe().' capitalised. Its cost is in the books now, so the monthly charge can be posted.');
    }

    /** Out of service, with a date, a reason and whatever came back. */
    public function dispose(Request $request, BusinessAsset $asset): RedirectResponse
    {
        $person = $request->user();
        $this->guard($asset, $person);

        $data = $request->validate([
            'disposed_on' => ['nullable', 'date', 'before_or_equal:today'],
            'disposal_reason' => ['required', 'string', 'max:255'],
            'disposal_proceeds' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->assets->dispose($asset, $data, $person);

        return back()->with('status', $asset->describe().' written off the working lists. It stays on the disposal register with its reason.');
    }

    /**
     * Post one month of depreciation for everything that is due.
     *
     * The heaviest action on the desk, so it says what it did: how many assets,
     * how much, and which ones were left alone and why. A run that posts nothing
     * is still a run worth recording.
     */
    public function runDepreciation(Request $request): RedirectResponse
    {
        $person = $request->user();

        $result = $this->assets->runDepreciation($person);

        $posted = count($result['posted']);
        $skipped = $result['skipped'];

        if ($posted === 0) {
            return back()->with('status', $skipped === []
                ? 'Nothing was due — every depreciating asset is already charged up to this month.'
                : 'Nothing was posted: '.implode(' ', array_slice($skipped, 0, 3)));
        }

        $message = 'Posted ৳'.number_format($result['total'], 2).' of depreciation across '.$posted.' asset(s), '
            .'one journal entry each — debiting '.AssetRegistry::DEPRECIATION_EXPENSE_CODE.' and crediting '.AssetRegistry::ACCUMULATED_DEPRECIATION_CODE.'.';

        if ($skipped !== []) {
            $message .= ' Left alone: '.implode(' ', array_slice($skipped, 0, 3));
        }

        return back()->with('status', $message);
    }

    /** Log a run: where the vehicle went, and what the run cost. */
    public function logTrip(Request $request): RedirectResponse
    {
        $person = $request->user();

        $data = $request->validate([
            'business_asset_id' => ['required', 'integer'],
            'trip_date' => ['required', 'date', 'before_or_equal:today'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date', 'after_or_equal:started_at'],
            'from_location' => ['nullable', 'string', 'max:160'],
            'to_location' => ['nullable', 'string', 'max:160'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'driver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $person->company_id)],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'odometer_start' => ['nullable', 'integer', 'min:0'],
            'odometer_end' => ['nullable', 'integer', 'min:0'],
            'fuel_litres' => ['nullable', 'numeric', 'min:0'],
            'fuel_cost' => ['nullable', 'numeric', 'min:0'],
            'other_cost' => ['nullable', 'numeric', 'min:0'],
            'cost_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = $this->assets->visible((int) $person->company_id)->find((int) $data['business_asset_id']);

        abort_if($asset === null, 404, 'That vehicle is not on this company’s register.');
        $this->guard($asset, $person);

        $trip = $this->assets->logTrip($asset, $data, $person);

        return back()->with('status', sprintf(
            'Trip logged: %s on %s%s.',
            $trip->routeLabel(),
            $trip->trip_date->format('d M Y'),
            $trip->distance_km !== null ? ' — '.number_format((float) $trip->distance_km, 2).' km' : ' (no odometer readings, so no distance)',
        ));
    }

    /** Hang a certificate, policy or contract on this asset. */
    public function linkRecord(Request $request, BusinessAsset $asset): RedirectResponse
    {
        $person = $request->user();
        $this->guard($asset, $person);

        $data = $request->validate(['record_id' => ['required', 'integer']]);

        $record = BusinessRecord::query()
            ->where('company_id', $asset->company_id)
            ->find((int) $data['record_id']);

        abort_if($record === null, 404, 'That paper is not on this company’s registers.');

        $this->assets->linkRecord($asset, $record, $person);

        return back()->with('status', $record->title.' now hangs on '.$asset->describe().', and still appears on the renewals lens and the compliance calendar.');
    }

    public function unlinkRecord(Request $request, BusinessAsset $asset, BusinessRecord $record): RedirectResponse
    {
        $person = $request->user();
        $this->guard($asset, $person);

        $this->assets->unlinkRecord($asset, $record, $person);

        return back()->with('status', $record->title.' unlinked. It stays on the certificate register.');
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * Another company's asset, or one on a branch this person cannot see, is a
     * 404. An asset is read-only for people without the manage right, which the
     * routes already enforce — this is the row-level half.
     */
    private function guard(BusinessAsset $asset, User $person): void
    {
        abort_unless((int) $asset->company_id === (int) $person->company_id, 404);

        $allowed = $person->accessibleBranchIds();

        if ($allowed !== null && $asset->branch_id !== null) {
            abort_unless(in_array((int) $asset->branch_id, array_map('intval', $allowed), true), 404);
        }
    }

    private function manages(User $person): bool
    {
        return (bool) $person->can('business.assets.manage');
    }

    /** @return Collection<int, Branch> */
    private function branchOptions(User $person): Collection
    {
        $ids = $person->accessibleBranchIds();

        return Branch::query()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /** @return Collection<int, User> */
    private function people(User $person): Collection
    {
        return User::query()
            ->where('company_id', $person->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, BusinessAsset> the vehicles a person may log against */
    private function vehicleOptions(User $person): Collection
    {
        return $this->assets->visible()->vehicles()->live()->orderBy('name')->get(['id', 'name', 'code', 'registration_no']);
    }

    /**
     * The papers hanging on a set of vehicles, keyed by asset, so the vehicle list
     * can show the next fitness or insurance date without a query per row.
     *
     * @param  array<int, int>  $ids
     * @return Collection<int, Collection<int, BusinessRecord>>
     */
    private function papers(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return BusinessRecord::query()
            ->whereIn('business_asset_id', $ids)
            ->where('status', BusinessRecord::STATUS_ACTIVE)
            ->orderBy('expires_on')
            ->orderBy('due_on')
            ->get()
            ->groupBy('business_asset_id');
    }
}
