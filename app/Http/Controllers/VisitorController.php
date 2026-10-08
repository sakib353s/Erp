<?php

namespace App\Http\Controllers;

use App\Domain\Business\Services\VisitorService;
use App\Domain\Business\Visitor;
use App\Domain\Business\VisitorRegistry;
use App\Domain\Business\VisitorVisit;
use App\Http\Requests\StoreVisitorRequest;
use App\Http\Requests\StoreVisitorVisitRequest;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * §12-16 — the visitor desk.
 *
 * The gate asks four questions in a day: who is expected, who is inside, who
 * came in, and who keeps coming. So the desk is one log with four lenses over
 * it — the day's arrivals, the pre-registration diary, the register of people,
 * and the reports — rather than four screens that disagree about what a visit
 * is.
 *
 * Everything that decides anything lives in `VisitorService`; this class reads
 * the request, calls the engine, and puts the refusal on the page when the
 * engine says no.
 */
class VisitorController extends Controller
{
    public function __construct(protected VisitorService $visitors) {}

    /** The log: everybody at the gate for the window being looked at. */
    public function index(Request $request): View
    {
        $day = $this->day($request->query('day'));

        $visits = $this->visitors->visible()
            ->with(['visitor', 'host', 'branch'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('purpose'), fn ($query) => $query->where('purpose', (string) $request->query('purpose')))
            ->when($request->filled('branch'), function ($query) use ($request) {
                $branch = (int) $request->query('branch');

                $branch > 0 ? $query->where('branch_id', $branch) : $query->whereNull('branch_id');
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.trim((string) $request->query('q')).'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('badge_no', 'like', $term)
                        ->orWhereHas('visitor', fn ($visitor) => $visitor->where('name', 'like', $term)->orWhere('phone', 'like', $term)->orWhere('organisation', 'like', $term))
                        ->orWhereHas('host', fn ($host) => $host->where('name', 'like', $term));
                });
            })
            ->when($day !== null, fn ($query) => $query->onDay($day))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('business.visitors.index', [
            'visits' => $visits,
            'canManage' => $request->user()->can('business.visitors.manage'),
            'day' => $day,
            'purposes' => VisitorRegistry::PURPOSES,
            'statuses' => VisitorVisit::STATUSES,
            'branches' => $this->branches($request),
            'summary' => $this->visitors->summary(),
            'inside' => $this->visitors->today()['inside'],
            'overstaying' => $this->visitors->forgotten(),
        ]);
    }

    /** Book somebody in ahead of time. */
    public function create(Request $request): View
    {
        return view('business.visitors.form', [
            'mode' => 'book',
            'visit' => null,
            'people' => $this->visitors->people(),
            'hosts' => $this->hosts($request),
            'branches' => $this->branches($request),
            'purposes' => VisitorRegistry::PURPOSES,
            'preselected' => $request->query('visitor') !== null ? (int) $request->query('visitor') : null,
        ]);
    }

    public function store(StoreVisitorVisitRequest $request): RedirectResponse
    {
        try {
            $visit = $this->visitors->preRegister($request->validated(), $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['visitor_id' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.show', $visit)->with(
            'status',
            "{$visit->visitor?->name} is expected on ".$visit->scheduled_for?->format('d M Y \a\t H:i').' — no badge has been issued yet.'
        );
    }

    /** Somebody is standing at the gate and nobody booked them. */
    public function walkIn(Request $request): View
    {
        return view('business.visitors.form', [
            'mode' => 'walkin',
            'visit' => null,
            'people' => $this->visitors->people(),
            'hosts' => $this->hosts($request),
            'branches' => $this->branches($request),
            'purposes' => VisitorRegistry::PURPOSES,
            'preselected' => null,
        ]);
    }

    public function storeWalkIn(StoreVisitorVisitRequest $request): RedirectResponse
    {
        try {
            $visit = $this->visitors->checkIn($request->user(), $request->validated());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['visitor_id' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.show', $visit)->with(
            'status',
            "{$visit->visitor?->name} checked in at ".$visit->checked_in_at?->format('H:i')." with badge {$visit->badge_no}."
        );
    }

    /** One visit, from booking to checkout. */
    public function show(Request $request, VisitorVisit $visit): View
    {
        $this->authorizeVisit($request, $visit);

        return view('business.visitors.show', [
            'visit' => $visit->load(['visitor', 'host', 'branch', 'registrar', 'checkInBy', 'checkOutBy']),
            'canManage' => $request->user()->can('business.visitors.manage'),
            'history' => $this->visitors->visible()
                ->where('visitor_id', $visit->visitor_id)
                ->whereKeyNot($visit->id)
                ->with('host')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get(),
        ]);
    }

    /** A booked visitor is at the gate. */
    public function checkIn(Request $request, VisitorVisit $visit): RedirectResponse
    {
        $this->authorizeVisit($request, $visit);

        try {
            $visit = $this->visitors->checkIn($request->user(), [], $visit);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['badge_no' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.show', $visit)->with(
            'status',
            "{$visit->visitor?->name} checked in — badge {$visit->badge_no}."
        );
    }

    public function checkOut(Request $request, VisitorVisit $visit): RedirectResponse
    {
        $this->authorizeVisit($request, $visit);

        $note = (string) $request->input('note', '');

        try {
            $visit = $this->visitors->checkOut($visit, $request->user(), $note);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['note' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.show', $visit)->with(
            'status',
            "{$visit->visitor?->name} checked out after {$visit->dwellLabel()} — badge {$visit->badge_no} is back."
        );
    }

    public function cancel(Request $request, VisitorVisit $visit): RedirectResponse
    {
        $this->authorizeVisit($request, $visit);

        $reason = (string) $request->input('cancel_reason', '');

        try {
            $this->visitors->cancel($visit, $request->user(), $reason);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['cancel_reason' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.expected')->with('status', "The booking for {$visit->visitor?->name} is cancelled.");
    }

    public function noShow(Request $request, VisitorVisit $visit): RedirectResponse
    {
        $this->authorizeVisit($request, $visit);

        try {
            $this->visitors->markNoShow($visit, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withErrors(['cancel_reason' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.expected')->with(
            'status',
            "{$visit->visitor?->name} never arrived on ".$visit->scheduled_for?->format('d M').' — the diary says so now.'
        );
    }

    /** The pre-registration diary: today, tomorrow, and the rest of the week. */
    public function expected(Request $request): View
    {
        return view('business.visitors.expected', [
            'canManage' => $request->user()->can('business.visitors.manage'),
            'diary' => $this->visitors->diary(7),
            'missed' => $this->visitors->today()['noShows'],
            'summary' => $this->visitors->summary(),
        ]);
    }

    /** The reports lens: the same rows, cut five ways. */
    public function reports(Request $request): View
    {
        return view('business.visitors.reports', [
            'canManage' => $request->user()->can('business.visitors.manage'),
            'report' => $this->visitors->report($request->query('from'), $request->query('to')),
            'summary' => $this->visitors->summary(),
            'blacklisted' => $this->visitors->people(true),
        ]);
    }

    /** Everybody on file, with the one flag the gate acts on. */
    public function people(Request $request): View
    {
        return view('business.visitors.people', [
            'canManage' => $request->user()->can('business.visitors.manage'),
            'people' => $this->visitors->people(false, $request->query('q')),
            'search' => (string) $request->query('q', ''),
            'summary' => $this->visitors->summary(),
        ]);
    }

    public function createPerson(Request $request): View
    {
        return view('business.visitors.person-form', [
            'person' => null,
            'idTypes' => Visitor::ID_TYPES,
        ]);
    }

    public function storePerson(StoreVisitorRequest $request): RedirectResponse
    {
        try {
            $visitor = $this->visitors->person($request->validated(), $request->user());
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['name' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.people')->with(
            'status',
            $visitor->wasRecentlyCreated
                ? "{$visitor->name} added to the visitor register."
                : "{$visitor->name} was already on file — the visit history stays in one place."
        );
    }

    public function blacklist(Request $request, Visitor $visitor): RedirectResponse
    {
        abort_unless((int) $visitor->company_id === (int) $request->user()->company_id, 404);

        $reason = (string) $request->input('blacklist_reason', '');

        try {
            $this->visitors->blacklist($visitor, $request->user(), $reason);
        } catch (\RuntimeException $error) {
            return back()->withErrors(['blacklist_reason' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.people')->with('status', "{$visitor->name} is on the blacklist — the gate will refuse them.");
    }

    public function restore(Request $request, Visitor $visitor): RedirectResponse
    {
        abort_unless((int) $visitor->company_id === (int) $request->user()->company_id, 404);

        try {
            $this->visitors->restore($visitor, $request->user());
        } catch (\RuntimeException $error) {
            return back()->withErrors(['blacklist_reason' => $error->getMessage()]);
        }

        return redirect()->route('business.visitors.people')->with('status', "{$visitor->name} may be admitted again.");
    }

    /* --------------------------------------------------------------- internals */

    /** A visit from another company is not a 403 — it does not exist here. */
    protected function authorizeVisit(Request $request, VisitorVisit $visit): void
    {
        abort_unless((int) $visit->company_id === (int) $request->user()->company_id, 404);

        $ids = $request->user()->accessibleBranchIds();

        abort_unless($ids === null || $visit->branch_id === null || in_array((int) $visit->branch_id, $ids, true), 403);
    }

    protected function day(mixed $value): ?Carbon
    {
        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '' || $raw === 'all') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function branches(Request $request)
    {
        return \App\Domain\Foundation\Branch::query()
            ->where('company_id', (int) $request->user()->company_id)
            ->orderBy('name')
            ->get();
    }

    /** The people a visitor can be here to see: the company's own users. */
    protected function hosts(Request $request)
    {
        return \App\Domain\Foundation\User::query()
            ->where('company_id', (int) $request->user()->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
