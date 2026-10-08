<?php

namespace App\Domain\Business\Services;

use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Business\Visitor;
use App\Domain\Business\VisitorRegistry;
use App\Domain\Business\VisitorVisit;
use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Services\TenantContext;
use App\Domain\Foundation\User;
use App\Domain\Notification\Services\NotificationCenter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * §12-16 — the gate's engine.
 *
 * Three things are being kept true here, and none of them is a form.
 *
 * **A booking is not an arrival.** Pre-registering a visit writes a row that
 * says who is expected, by whom, on what day and why — and gives out no badge
 * and records no time in. The visitor's side of the desk only fills in when
 * somebody is actually standing at it, which is what makes "who is inside the
 * building right now" a question the register can answer.
 *
 * **The blacklist is the one thing a gate must never get wrong.** A person who
 * has been sent away is refused at the door *and* refused at booking time —
 * which is why the check lives in this service rather than in a controller or a
 * dropdown — and nobody is written onto that list while they are still inside
 * the building, because imposing it is a decision for after they have left.
 *
 * **How long somebody stayed is arithmetic.** The two timestamps are the facts;
 * the minutes between them are computed whenever they are asked for, so a
 * register can never disagree with its own audit trail.
 */
class VisitorService
{
    /** Badges come out of a per-day series: VB-20261008-01. */
    public const BADGE_PREFIX = 'VB-';

    public function __construct(
        protected AuditRecorder $audit,
        protected TenantContext $context,
        protected NotificationCenter $notifications,
    ) {}

    public function companyId(): int
    {
        return (int) ($this->context->companyId() ?? abort(500, 'No company context for the visitor desk.'));
    }

    /* ---------------------------------------------------------------- the people */

    /**
     * Everybody this company has on file, newest first.
     *
     * @return Collection<int, Visitor>
     */
    public function people(bool $blacklistedOnly = false, ?string $search = null): Collection
    {
        return Visitor::query()
            ->where('company_id', $this->companyId())
            ->when($blacklistedOnly, fn (Builder $query) => $query->where('is_blacklisted', true))
            ->when($search !== null && trim($search) !== '', function (Builder $query) use ($search) {
                $term = '%'.trim($search).'%';

                $query->where(function (Builder $inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('organisation', 'like', $term);
                });
            })
            ->withCount('visits')
            ->with('visits')
            ->orderBy('name')
            ->get();
    }

    /**
     * The person behind a booking: either somebody already on file, or somebody
     * new described well enough to be found again next time (a name, and a phone
     * if the gate has one — a name alone is not a way to reach anybody).
     *
     * @param  array{visitor_id?:int|null, name?:string|null, phone?:string|null, email?:string|null,
     *               organisation?:string|null, id_type?:string|null, id_number?:string|null, notes?:string|null}  $data
     */
    public function person(array $data, User $actor): Visitor
    {
        $companyId = $this->companyId();

        if (($data['visitor_id'] ?? null) !== null) {
            $visitor = Visitor::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $data['visitor_id'])
                ->first();

            if ($visitor === null) {
                throw new RuntimeException('That person is not in this company\'s visitor register.');
            }

            return $visitor;
        }

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('A visitor needs a name — nobody can be announced without one.');
        }

        $phone = $this->blankToNull($data['phone'] ?? null);

        // Somebody who has been here before is the same person, not a second
        // row: match on the phone when there is one, and on the exact name
        // otherwise, so the history stays in one place.
        $existing = Visitor::query()
            ->where('company_id', $companyId)
            ->where(function (Builder $query) use ($name, $phone) {
                $query->where('name', $name);

                if ($phone !== null) {
                    $query->orWhere('phone', $phone);
                }
            })
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $visitor = Visitor::query()->create([
            'company_id' => $companyId,
            'name' => $name,
            'phone' => $phone,
            'email' => $this->blankToNull($data['email'] ?? null),
            'organisation' => $this->blankToNull($data['organisation'] ?? null),
            'id_type' => $this->idType($data['id_type'] ?? null),
            'id_number' => $this->blankToNull($data['id_number'] ?? null),
            'notes' => $this->blankToNull($data['notes'] ?? null),
        ]);

        $this->audit->record([
            'action' => 'business.visitor_registered',
            'entity_type' => 'visitor',
            'entity_id' => $visitor->id,
            'actor_id' => $actor->id,
            'after' => ['name' => $visitor->name, 'organisation' => $visitor->organisation],
        ]);

        return $visitor;
    }

    /** Write somebody onto the list the gate acts on. */
    public function blacklist(Visitor $visitor, User $actor, string $reason): Visitor
    {
        $this->assertVisitor($visitor);

        $reason = trim($reason);

        if (mb_strlen($reason) < 4) {
            throw new RuntimeException('A blacklist entry needs a reason — the next person at the gate has to know why.');
        }

        $inside = VisitorVisit::query()
            ->where('company_id', $this->companyId())
            ->where('visitor_id', $visitor->id)
            ->where('status', VisitorVisit::STATUS_INSIDE)
            ->exists();

        if ($inside) {
            throw new RuntimeException("{$visitor->name} is inside the building right now. Check them out first, then decide.");
        }

        $before = ['is_blacklisted' => $visitor->is_blacklisted, 'blacklist_reason' => $visitor->blacklist_reason];

        $visitor->fill([
            'is_blacklisted' => true,
            'blacklist_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'business.visitor_blacklisted',
            'entity_type' => 'visitor',
            'entity_id' => $visitor->id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => ['reason' => $reason],
        ]);

        return $visitor;
    }

    /** Lift it — an old decision, revisited on purpose. */
    public function restore(Visitor $visitor, User $actor): Visitor
    {
        $this->assertVisitor($visitor);

        $before = ['is_blacklisted' => $visitor->is_blacklisted, 'blacklist_reason' => $visitor->blacklist_reason];

        $visitor->fill([
            'is_blacklisted' => false,
            'blacklist_reason' => null,
        ])->save();

        $this->audit->record([
            'action' => 'business.visitor_restored',
            'entity_type' => 'visitor',
            'entity_id' => $visitor->id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => ['is_blacklisted' => false],
        ]);

        return $visitor;
    }

    /* ----------------------------------------------------------------- the log */

    /**
     * Every visit the reader may see: this company's, and — for somebody scoped
     * to branches — only the ones at their own gate. Company first, always.
     */
    public function visible(?User $reader = null): Builder
    {
        $ids = ($reader ?? auth()->user())?->accessibleBranchIds()
            ?? $this->context->accessibleBranchIds();

        return VisitorVisit::query()
            ->where('company_id', $this->companyId())
            ->when($ids !== null, fn (Builder $query) => $query->where(function (Builder $inner) use ($ids) {
                $inner->whereIn('branch_id', $ids)->orWhereNull('branch_id');
            }));
    }

    /**
     * Book somebody in ahead of time.
     *
     * @param  array{visitor_id?:int|null, name?:string|null, phone?:string|null, organisation?:string|null,
     *               id_type?:string|null, id_number?:string|null, host_user_id?:int|null, branch_id?:int|null,
     *               purpose:string, scheduled_for:string, meet_at?:string|null, items_carried?:string|null,
     *               vehicle_no?:string|null, notes?:string|null}  $data
     */
    public function preRegister(array $data, User $actor): VisitorVisit
    {
        $visitor = $this->person($data, $actor);
        $this->refuseBlacklisted($visitor);

        $purpose = $this->purpose($data['purpose'] ?? null);
        $at = $this->moment($data['scheduled_for'] ?? null);
        $today = Carbon::today()->startOfDay();

        if ($at->lt($today)) {
            throw new RuntimeException('That day has already passed — a booking is for today or later. Somebody standing at the gate today is a walk-in.');
        }

        if ($at->gt($today->copy()->addDays(VisitorVisit::BOOKING_HORIZON_DAYS))) {
            throw new RuntimeException('The gate keeps a diary, not a calendar: bookings are taken up to '.VisitorVisit::BOOKING_HORIZON_DAYS.' days ahead.');
        }

        $visit = VisitorVisit::query()->create([
            'company_id' => $this->companyId(),
            'branch_id' => $this->branch($data['branch_id'] ?? null, $actor),
            'visitor_id' => $visitor->id,
            'host_user_id' => $this->host($data['host_user_id'] ?? null),
            'scheduled_for' => $at->toDateTimeString(),
            'purpose' => $purpose,
            'meet_at' => $this->blankToNull($data['meet_at'] ?? null),
            'items_carried' => $this->blankToNull($data['items_carried'] ?? null),
            'vehicle_no' => $this->blankToNull($data['vehicle_no'] ?? null),
            'status' => VisitorVisit::STATUS_EXPECTED,
            'registered_by' => $actor->id,
            'notes' => $this->blankToNull($data['notes'] ?? null),
        ]);

        $this->audit->record([
            'action' => 'business.visitor_expected',
            'entity_type' => 'visitor_visit',
            'entity_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'visitor' => $visitor->name,
                'purpose' => $purpose,
                'expected_at' => $at->toDateTimeString(),
            ],
        ]);

        return $visit->load(['visitor', 'host', 'branch']);
    }

    /**
     * The visitor is at the gate.
     *
     * Two shapes of the same event: somebody booked in earlier who has now
     * arrived (the visit is passed in), or somebody who simply walked in (no
     * visit, and the person is resolved or created from the data). Both allocate
     * a badge, both stamp the time, and the host hears about it once.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkIn(User $actor, array $data = [], ?VisitorVisit $visit = null): VisitorVisit
    {
        if ($visit === null) {
            $visitor = $this->person($data, $actor);
            $this->refuseBlacklisted($visitor);

            $visit = VisitorVisit::query()->create([
                'company_id' => $this->companyId(),
                'branch_id' => $this->branch($data['branch_id'] ?? null, $actor),
                'visitor_id' => $visitor->id,
                'host_user_id' => $this->host($data['host_user_id'] ?? null),
                'scheduled_for' => null,
                'purpose' => $this->purpose($data['purpose'] ?? null),
                'meet_at' => $this->blankToNull($data['meet_at'] ?? null),
                'items_carried' => $this->blankToNull($data['items_carried'] ?? null),
                'vehicle_no' => $this->blankToNull($data['vehicle_no'] ?? null),
                'status' => VisitorVisit::STATUS_EXPECTED,
                'registered_by' => $actor->id,
                'notes' => $this->blankToNull($data['notes'] ?? null),
            ]);
        }

        $this->assertVisit($visit);

        if (! $visit->isExpected()) {
            throw new RuntimeException("This visit is already {$visit->stateLabel()} — check-in happens once.");
        }

        $visitor = $visit->visitor ?? Visitor::query()->findOrFail($visit->visitor_id);
        $this->refuseBlacklisted($visitor);

        if ($visit->wasBooked() && ! $visit->scheduled_for->isToday()) {
            throw new RuntimeException(
                "{$visitor->name} is booked for ".$visit->scheduled_for->format('d M Y').
                ' — the gate takes them on that day. Cancel the booking if today is the day.'
            );
        }

        $before = $visit->only(['status', 'badge_no', 'checked_in_at']);

        $visit->fill([
            'badge_no' => $visit->badge_no ?? $this->allocateBadge(),
            'status' => VisitorVisit::STATUS_INSIDE,
            'checked_in_at' => Carbon::now(),
            'checked_in_by' => $actor->id,
        ])->save();

        $this->audit->record([
            'action' => 'business.visitor_checked_in',
            'entity_type' => 'visitor_visit',
            'entity_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'actor_id' => $actor->id,
            'before' => $before,
            'after' => ['badge_no' => $visit->badge_no, 'checked_in_at' => $visit->checked_in_at?->toDateTimeString()],
        ]);

        $this->announce($visit, $actor);

        return $visit->load(['visitor', 'host', 'branch']);
    }

    /** The visitor is leaving. */
    public function checkOut(VisitorVisit $visit, User $actor, ?string $note = null): VisitorVisit
    {
        $this->assertVisit($visit);

        if (! $visit->isInside()) {
            throw new RuntimeException(
                $visit->isExpected()
                    ? 'Nobody has checked in yet — there is nothing to check out.'
                    : 'This visit is already '.$visit->stateLabel().'.'
            );
        }

        $visit->fill([
            'status' => VisitorVisit::STATUS_OUT,
            'checked_out_at' => Carbon::now(),
            'checked_out_by' => $actor->id,
            'notes' => $note !== null && trim($note) !== ''
                ? trim($note)
                : $visit->notes,
        ])->save();

        $this->audit->record([
            'action' => 'business.visitor_checked_out',
            'entity_type' => 'visitor_visit',
            'entity_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'actor_id' => $actor->id,
            'after' => [
                'badge_no' => $visit->badge_no,
                'minutes' => $visit->dwellMinutes(),
            ],
        ]);

        return $visit->load(['visitor', 'host']);
    }

    /** The booking is off — the host called it, or the visitor did. */
    public function cancel(VisitorVisit $visit, User $actor, string $reason): VisitorVisit
    {
        $this->assertVisit($visit);

        if (! $visit->isExpected()) {
            throw new RuntimeException('Only a booking that has not happened yet can be cancelled.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Say why the booking is off — the next person reading the gate diary needs to know.');
        }

        $visit->fill([
            'status' => VisitorVisit::STATUS_CANCELLED,
            'cancel_reason' => $reason,
        ])->save();

        $this->audit->record([
            'action' => 'business.visitor_cancelled',
            'entity_type' => 'visitor_visit',
            'entity_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'actor_id' => $actor->id,
            'after' => ['reason' => $reason],
        ]);

        return $visit;
    }

    /** The booked day came and went. */
    public function markNoShow(VisitorVisit $visit, User $actor): VisitorVisit
    {
        $this->assertVisit($visit);

        if (! $visit->isNoShowBy()) {
            throw new RuntimeException('A no-show is a booking whose day has passed — this one is '.strtolower($visit->stateLabel()).'.');
        }

        $visit->fill(['status' => VisitorVisit::STATUS_NO_SHOW])->save();

        $this->audit->record([
            'action' => 'business.visitor_no_show',
            'entity_type' => 'visitor_visit',
            'entity_id' => $visit->id,
            'branch_id' => $visit->branch_id,
            'actor_id' => $actor->id,
            'after' => ['expected_at' => $visit->scheduled_for?->toDateTimeString()],
        ]);

        return $visit;
    }

    /* --------------------------------------------------------------- the lenses */

    /**
     * The desk's three questions, all read from the clock.
     *
     * @return array{expected:Collection<int, VisitorVisit>, inside:Collection<int, VisitorVisit>, left:Collection<int, VisitorVisit>, noShows:Collection<int, VisitorVisit>}
     */
    public function today(): array
    {
        $today = Carbon::today();

        $base = fn () => $this->visible()->with(['visitor', 'host', 'branch'])->orderBy('scheduled_for');

        return [
            'expected' => $base()
                ->where('status', VisitorVisit::STATUS_EXPECTED)
                ->whereDate('scheduled_for', $today->toDateString())
                ->get(),
            'inside' => $base()
                ->where('status', VisitorVisit::STATUS_INSIDE)
                ->orderBy('checked_in_at')
                ->get(),
            'left' => $base()
                ->where('status', VisitorVisit::STATUS_OUT)
                ->whereDate('checked_out_at', $today->toDateString())
                ->orderByDesc('checked_out_at')
                ->get(),
            'noShows' => $base()
                ->where('status', VisitorVisit::STATUS_EXPECTED)
                ->whereDate('scheduled_for', '<', $today->toDateString())
                ->get(),
        ];
    }

    /** The booking diary: today, tomorrow, and the rest of the horizon. */
    public function diary(int $days = 7): array
    {
        $today = Carbon::today();

        $todayKey = $today->toDateString();
        $tomorrowKey = $today->copy()->addDay()->toDateString();

        // Grouped into a plain array by the day the visit belongs to, so an empty
        // day is an empty list rather than a missing key the views have to test.
        $booked = $this->visible()
            ->with(['visitor', 'host', 'branch'])
            ->where('status', VisitorVisit::STATUS_EXPECTED)
            ->whereDate('scheduled_for', '>=', $todayKey)
            ->whereDate('scheduled_for', '<=', $today->copy()->addDays($days)->toDateString())
            ->orderBy('scheduled_for')
            ->get()
            ->groupBy(fn (VisitorVisit $visit) => $visit->scheduled_for?->toDateString())
            ->all();

        // The buckets are model collections, not arrays of attributes: `collect()`
        // over an Eloquent collection would have converted every row with
        // `toArray()` and the views would then be reading arrays where they
        // expect a visit.
        $bucket = fn (string $key): EloquentCollection => new EloquentCollection(
            isset($booked[$key]) ? $booked[$key]->all() : []
        );

        return [
            'today' => $bucket($todayKey),
            'tomorrow' => $bucket($tomorrowKey),
            'rest' => new EloquentCollection(\Illuminate\Support\Arr::except($booked, [$todayKey, $tomorrowKey])),
        ];
    }

    /** Somebody came in and has not been seen leaving. */
    public function overstaying(int $hours = VisitorVisit::OVERSTAY_HOURS): Collection
    {
        return $this->visible()
            ->with(['visitor', 'host', 'branch'])
            ->where('status', VisitorVisit::STATUS_INSIDE)
            ->where('checked_in_at', '<=', Carbon::now()->subHours($hours))
            ->orderBy('checked_in_at')
            ->get();
    }

    /** Yesterday's people who never went home, which only happens by mistake. */
    public function forgotten(): Collection
    {
        return $this->visible()
            ->with(['visitor', 'host', 'branch'])
            ->where('status', VisitorVisit::STATUS_INSIDE)
            ->where('checked_in_at', '<', Carbon::today()->toDateString())
            ->orderBy('checked_in_at')
            ->get();
    }

    /**
     * The desk's figures. Everything here is counted from rows, never stored.
     *
     * @return array<string, int|float|string>
     */
    public function summary(): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $todayVisits = $this->visible()->onDay($today)->get();
        $month = $this->visible()->where('created_at', '>=', $monthStart)->get();

        return [
            'inside' => $this->visible()->where('status', VisitorVisit::STATUS_INSIDE)->count(),
            'expected_today' => $this->visible()->where('status', VisitorVisit::STATUS_EXPECTED)
                ->whereDate('scheduled_for', $today->toDateString())->count(),
            'arrived_today' => $todayVisits->whereNotNull('checked_in_at')->count(),
            'left_today' => $todayVisits->where('status', VisitorVisit::STATUS_OUT)->count(),
            'walk_ins_today' => $todayVisits->whereNull('scheduled_for')->count(),
            'month_visits' => $month->count(),
            'month_no_shows' => $month->where('status', VisitorVisit::STATUS_NO_SHOW)->count(),
            'month_cancelled' => $month->where('status', VisitorVisit::STATUS_CANCELLED)->count(),
            'average_minutes' => $this->averageMinutes($month),
            'on_file' => Visitor::query()->where('company_id', $this->companyId())->count(),
            'blacklisted' => Visitor::query()->where('company_id', $this->companyId())->where('is_blacklisted', true)->count(),
            'longest_open' => (int) round((float) $this->visible()
                ->where('status', VisitorVisit::STATUS_INSIDE)
                ->get()
                ->max(fn (VisitorVisit $visit) => $visit->checked_in_at !== null
                    ? $visit->checked_in_at->diffInMinutes(Carbon::now(), false)
                    : 0)),
        ];
    }

    /**
     * The reports lens: the same rows, cut five ways, over a window.
     *
     * @return array<string, mixed>
     */
    public function report(?string $from = null, ?string $to = null): array
    {
        $end = $to !== null ? Carbon::parse($to)->endOfDay() : Carbon::today()->endOfDay();
        $start = $from !== null ? Carbon::parse($from)->startOfDay() : $end->copy()->subDays(29)->startOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $visits = $this->visible()
            ->with(['visitor', 'host', 'branch'])
            ->where('created_at', '>=', $start)
            ->where('created_at', '<=', $end)
            ->get();

        $completed = $visits->filter(fn (VisitorVisit $visit) => $visit->dwellMinutes() !== null);

        $byDay = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $byDay[$cursor->toDateString()] = 0;
            $cursor->addDay();
        }

        foreach ($visits as $visit) {
            $key = $visit->day()?->toDateString();

            if ($key !== null && array_key_exists($key, $byDay)) {
                $byDay[$key]++;
            }
        }

        $group = fn (callable $keyOf): array => $visits
            ->groupBy($keyOf)
            ->map(fn (Collection $rows) => $rows->count())
            ->sortDesc()
            ->all();

        $busiest = $completed->sortByDesc(fn (VisitorVisit $visit) => $visit->dwellMinutes())->first();
        $peak = ! empty($byDay) ? array_search(max($byDay), $byDay, true) : null;

        return [
            'from' => $start,
            'to' => $end,
            'visits' => $visits->count(),
            'arrived' => $visits->whereNotNull('checked_in_at')->count(),
            'still_inside' => $visits->where('status', VisitorVisit::STATUS_INSIDE)->count(),
            'no_shows' => $visits->where('status', VisitorVisit::STATUS_NO_SHOW)->count(),
            'cancelled' => $visits->where('status', VisitorVisit::STATUS_CANCELLED)->count(),
            'walk_ins' => $visits->whereNull('scheduled_for')->count(),
            'pre_booked' => $visits->whereNotNull('scheduled_for')->count(),
            'average_minutes' => $this->averageMinutes($completed),
            'longest' => $busiest,
            'unique_people' => $visits->pluck('visitor_id')->unique()->count(),
            'returning_people' => $visits->groupBy('visitor_id')->filter(fn (Collection $rows) => $rows->count() > 1)->count(),
            'by_day' => $byDay,
            'busiest_day' => $peak,
            'by_branch' => $group(fn (VisitorVisit $visit) => $visit->branch?->name ?? 'Company-wide'),
            'by_host' => $group(fn (VisitorVisit $visit) => $visit->host?->name ?? 'Nobody named'),
            'by_purpose' => $group(fn (VisitorVisit $visit) => $visit->purposeLabel()),
            'by_status' => $group(fn (VisitorVisit $visit) => $visit->stateLabel()),
            'repeat_visitors' => $visits
                ->groupBy('visitor_id')
                ->map(fn (Collection $rows) => ['visitor' => $rows->first()->visitor, 'visits' => $rows->count()])
                ->sortByDesc('visits')
                ->take(10)
                ->values(),
        ];
    }

    /* -------------------------------------------------------------- the registers */

    /** A badge number nobody in this company has been given today. */
    public function allocateBadge(?Carbon $day = null): string
    {
        $companyId = $this->companyId();
        $stamp = ($day ?? Carbon::today())->format('Ymd');
        $prefix = self::BADGE_PREFIX.$stamp.'-';

        $issued = VisitorVisit::query()
            ->where('company_id', $companyId)
            ->where('badge_no', 'like', $prefix.'%')
            ->count();

        $number = $issued + 1;

        do {
            $badge = $prefix.str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $number++;
        } while (VisitorVisit::query()->where('company_id', $companyId)->where('badge_no', $badge)->exists());

        return $badge;
    }

    /* --------------------------------------------------------------- internals */

    protected function announce(VisitorVisit $visit, User $actor): void
    {
        $visit->loadMissing(['visitor', 'host']);

        $host = $visit->host;

        if ($host === null) {
            return; // nobody claimed them; the desk still has the row
        }

        $this->notifications->notify(
            $host,
            'business.visitor.arrived',
            ($visit->visitor?->name ?? 'A visitor').' is at the gate',
            ($visit->visitor?->organisation ? $visit->visitor->organisation.' — ' : '')
                .$visit->purposeLabel().', badge '.$visit->badge_no
                .' (checked in by '.$actor->name.').',
            [
                'priority' => 'normal',
                'action_url' => '/app/visitors/'.$visit->id,
                'data' => [
                    'visit_id' => $visit->id,
                    'visitor' => $visit->visitor?->name,
                    'badge_no' => $visit->badge_no,
                ],
                // One arrival, one bell.
                'dedupe_key' => "business.visitor.arrived.{$visit->id}",
            ],
        );
    }

    protected function averageMinutes(Collection $visits): ?int
    {
        $durations = $visits
            ->map(fn (VisitorVisit $visit) => $visit->dwellMinutes())
            ->filter(fn (?int $minutes) => $minutes !== null);

        return $durations->isEmpty() ? null : (int) round($durations->avg());
    }

    protected function refuseBlacklisted(Visitor $visitor): void
    {
        if (! $visitor->isBlacklisted()) {
            return;
        }

        throw new RuntimeException(
            "{$visitor->name} is on the blacklist".($visitor->blacklist_reason ? ': '.$visitor->blacklist_reason : '.')
            .' The gate does not open for them; lift the entry first if that has changed.'
        );
    }

    protected function assertVisitor(Visitor $visitor): void
    {
        if ((int) $visitor->company_id !== $this->companyId()) {
            throw new RuntimeException('That person belongs to another company.');
        }
    }

    protected function assertVisit(VisitorVisit $visit): void
    {
        if ((int) $visit->company_id !== $this->companyId()) {
            throw new RuntimeException('That visit belongs to another company.');
        }
    }

    protected function purpose(mixed $value): string
    {
        $purpose = is_string($value) ? strtolower(trim($value)) : '';

        if (! array_key_exists($purpose, VisitorRegistry::PURPOSES)) {
            throw new RuntimeException('Pick a reason for the visit from the list — the gate logs why somebody is inside.');
        }

        return $purpose;
    }

    protected function moment(mixed $value): Carbon
    {
        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '') {
            throw new RuntimeException('A booking needs a day and a time.');
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            throw new RuntimeException('That day and time could not be read.');
        }
    }

    /** The host has to be somebody in this company who can be told. */
    protected function host(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $userId = (int) $value;

        $exists = User::query()
            ->where('company_id', $this->companyId())
            ->whereKey($userId)
            ->exists();

        if (! $exists) {
            throw new RuntimeException('The person being visited has to be somebody in this company.');
        }

        return $userId;
    }

    /**
     * The gate is a place: somebody scoped to branches books visits at their own
     * gate, and a company-wide reader may book at any of them.
     */
    protected function branch(mixed $value, User $actor): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $branchId = (int) $value;

        $branch = Branch::query()
            ->where('company_id', $this->companyId())
            ->whereKey($branchId)
            ->first();

        if ($branch === null) {
            throw new RuntimeException('That branch is not one of this company\'s.');
        }

        $allowed = $actor->accessibleBranchIds();

        if ($allowed !== null && ! in_array((int) $branch->id, $allowed, true)) {
            throw new RuntimeException('You can only book visits at a branch you are posted to.');
        }

        return (int) $branch->id;
    }

    protected function idType(mixed $value): ?string
    {
        $type = is_string($value) ? strtolower(trim($value)) : '';

        return array_key_exists($type, Visitor::ID_TYPES) ? $type : null;
    }

    protected function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
