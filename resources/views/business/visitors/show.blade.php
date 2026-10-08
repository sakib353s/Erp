@php
    /* §12-16 — one visit: the booking, the badge, the two timestamps. */
    $today = \Carbon\Carbon::today();
    $minutesLabel = fn (?int $minutes): string => $minutes === null
        ? '—'
        : ($minutes >= 60 ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : $minutes.'m');
    $steps = [
        ['key' => 'booked', 'label' => 'Booked', 'at' => $visit->scheduled_for, 'done' => $visit->wasBooked()],
        ['key' => 'arrived', 'label' => 'At the gate', 'at' => $visit->checked_in_at, 'done' => $visit->checked_in_at !== null],
        ['key' => 'left', 'label' => 'Checked out', 'at' => $visit->checked_out_at, 'done' => $visit->checked_out_at !== null],
    ];
@endphp

<x-ui.page-header
    eyebrow="Business Management · Visitors · Visit"
    :title="$visit->visitor?->name ?? 'Visit'"
    :subtitle="$visit->visitor?->organisation
        ? 'Here from '.$visit->visitor->organisation.' — '.strtolower($visit->purposeLabel()).'.'
        : 'A '.strtolower($visit->purposeLabel()).' recorded at the gate.'"
    :pin="true">
    <x-slot:actions>
        @if ($visit->isInside())
            <span class="erp-chip erp-chip-soft"><i class="bi bi-person-check" aria-hidden="true"></i> Inside since {{ $visit->checked_in_at?->format('H:i') }}</span>
        @else
            <x-ui.status :value="$visit->status" :label="$visit->stateLabel()" lg />
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.index') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i> The log</a>
    </x-slot:actions>
</x-ui.page-header>

@if ($visit->visitor?->isBlacklisted())
    <div class="erp-note erp-note-danger mb-3">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <div>
            <strong>{{ $visit->visitor->name }} is on the blacklist.</strong>
            {{ $visit->visitor->blacklist_reason ?? 'No reason was recorded.' }}
            The gate refuses them until the entry is lifted on <a href="{{ route('business.visitors.people') }}">the register</a>.
        </div>
    </div>
@endif

@if ($visit->isOverstaying())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>Still inside after {{ $visit->checked_in_at?->diffForHumans(null, true) }}.</strong>
            Nobody is unaccounted for in a building that keeps its log straight — check them out when they leave.
        </div>
    </div>
@endif

<div class="erp-kpi-grid">
    <x-ui.kpi label="Badge" :value="$visit->badge_no ?? 'Not issued'" icon="bi-card-text" hero
              :hint="$visit->badge_no ? 'Issued at the gate, unique for this company' : 'A badge is written out when somebody actually arrives'" />
    <x-ui.kpi label="Purpose" :value="$visit->purposeLabel()" icon="{{ \App\Domain\Business\VisitorRegistry::icon($visit->purpose) }}"
              :hint="$visit->meet_at ?? 'No waiting place recorded'" />
    <x-ui.kpi label="Time inside" :value="$visit->isInside() ? $minutesLabel($visit->checked_in_at?->diffInMinutes(now(), false)) : $visit->dwellLabel()"
              icon="bi-hourglass-split"
              :hint="$visit->checked_out_at ? 'Between check-in '.$visit->checked_in_at?->format('H:i').' and check-out '.$visit->checked_out_at->format('H:i') : 'Counted from the timestamps, never stored'" />
    <x-ui.kpi label="Host" :value="$visit->host?->name ?? 'Nobody named'" icon="bi-person-lines-fill"
              :hint="$visit->host ? 'Told once, when the visitor arrived' : 'Nobody was told — the desk still has the row'" />
    <x-ui.kpi label="Visits by this person" :value="$history->count() + 1" icon="bi-arrow-repeat"
              hint="Every visit is a row; the person is one record" />
    <x-ui.kpi label="Gate" :value="$visit->branch?->name ?? 'Company-wide'" icon="bi-geo-alt"
              :hint="$visit->registered_by ? 'Booked by '.($visit->registrar?->name ?? 'someone since removed') : '—'" />
</div>

<section class="erp-card mb-3">
    <div class="erp-card-head">
        <div>
            <h2 class="erp-card-title">The visit</h2>
            <p class="erp-card-sub">Three moments: when it was written down, when the person was here, and when they left.</p>
        </div>
    </div>

    <div class="px-3 pb-2">
        @foreach ($steps as $step)
            <div class="erp-list-row">
                <div class="erp-list-row-main">
                    <span class="erp-cell-strong">
                        <i class="bi {{ $step['done'] ? 'bi-check-circle-fill text-success' : 'bi-circle' }} me-1" aria-hidden="true"></i>{{ $step['label'] }}
                    </span>
                    <div class="erp-td-muted">
                        {{ $step['at']?->format('d M Y · H:i') ?? ($step['done'] ? '—' : 'Not yet') }}
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    @if ($step['key'] === 'arrived' && $step['done'])
                        <span class="erp-td-muted">{{ $visit->checkInBy?->name ? 'by '.$visit->checkInBy->name : '' }}</span>
                    @endif
                    @if ($step['key'] === 'left' && $step['done'])
                        <span class="erp-td-muted">{{ $visit->checkOutBy?->name ? 'by '.$visit->checkOutBy->name : '' }}</span>
                    @endif
                    @if ($step['key'] === 'left' && $step['done'])
                        <span class="erp-chip erp-chip-outline">{{ $visit->dwellLabel() }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</section>

<div class="erp-split">
    <section class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The person</h2>
                <p class="erp-card-sub">What the gate knows, and what it is holding on file.</p>
            </div>
        </div>
        <div class="px-3 pb-3">
            <dl class="erp-dl erp-dl-tight">
                <dt>Name</dt>
                <dd>{{ $visit->visitor?->name ?? '—' }}</dd>
                <dt>Organisation</dt>
                <dd>{{ $visit->visitor?->organisation ?? '—' }}</dd>
                <dt>Phone</dt>
                <dd>{{ $visit->visitor?->phone ?? '—' }}</dd>
                <dt>Email</dt>
                <dd>{{ $visit->visitor?->email ?? '—' }}</dd>
                <dt>Paper shown</dt>
                <dd>{{ $visit->visitor?->idTypeLabel() ?? '—' }}{{ $visit->visitor?->maskedIdNumber() ? ' · '.$visit->visitor->maskedIdNumber() : '' }}</dd>
                <dt>Bringing in</dt>
                <dd>{{ $visit->items_carried ?? '—' }}</dd>
                <dt>Vehicle</dt>
                <dd>{{ $visit->vehicle_no ?? '—' }}</dd>
                <dt>Notes</dt>
                <dd>{{ $visit->notes ?? '—' }}</dd>
                @if ($visit->cancel_reason)
                    <dt>Cancelled because</dt>
                    <dd>{{ $visit->cancel_reason }}</dd>
                @endif
            </dl>
        </div>
    </section>

    <section class="erp-card">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">At the gate</h2>
                <p class="erp-card-sub">Each action is recorded once and never rewritten.</p>
            </div>
        </div>
        <div class="px-3 pb-3 d-flex flex-column gap-3">
            @if ($canManage && $visit->isExpected())
                <form method="POST" action="{{ route('business.visitors.check-in', $visit) }}" data-confirm="Check {{ $visit->visitor?->name }} in now?">
                    @csrf
                    @error('badge_no')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>
                        {{ $visit->wasBooked() ? 'They have arrived' : 'Check in' }}
                    </button>
                    <div class="form-text mt-2">
                        @if ($visit->wasBooked())
                            Issues today's next badge and stamps the time. A booking for another day is refused here.
                        @else
                            Issues today's next badge and stamps the time.
                        @endif
                    </div>
                </form>

                <form method="POST" action="{{ route('business.visitors.cancel', $visit) }}">
                    @csrf
                    @error('cancel_reason')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                    <label class="form-label" for="cancel_reason">The booking is off — why?</label>
                    <input class="form-control mb-2" type="text" name="cancel_reason" id="cancel_reason" maxlength="500" required>
                    <button class="btn btn-outline-secondary w-100" type="submit" data-confirm="Cancel this booking?">Cancel the booking</button>
                </form>

                @if ($visit->isNoShowBy())
                    <form method="POST" action="{{ route('business.visitors.no-show', $visit) }}" data-confirm="Record that {{ $visit->visitor?->name }} never arrived?">
                        @csrf
                        <button class="btn btn-outline-secondary w-100" type="submit">
                            <i class="bi bi-person-dash" aria-hidden="true"></i> They never arrived
                        </button>
                    </form>
                @endif
            @endif

            @if ($canManage && $visit->isInside())
                <form method="POST" action="{{ route('business.visitors.check-out', $visit) }}" data-confirm="Check {{ $visit->visitor?->name }} out and take back badge {{ $visit->badge_no }}?">
                    @csrf
                    @error('note')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
                    <label class="form-label" for="note">Anything to note on the way out?</label>
                    <input class="form-control mb-2" type="text" name="note" id="note" maxlength="500" placeholder="Optional">
                    <button class="btn btn-primary w-100" type="submit">
                        <i class="bi bi-box-arrow-right" aria-hidden="true"></i> Check out · badge {{ $visit->badge_no }}
                    </button>
                </form>
            @endif

            @if ($visit->isClosed())
                <p class="erp-td-muted mb-0">
                    This visit is finished. The row stays — a register that forgets is not a register — and the person keeps the history.
                </p>
            @endif

            <a class="btn btn-outline-secondary" href="{{ route('business.visitors.create', ['visitor' => $visit->visitor_id]) }}">
                <i class="bi bi-calendar-plus" aria-hidden="true"></i> Book them in again
            </a>
        </div>
    </section>
</div>

<section class="erp-card mt-3">
    <div class="erp-card-head">
        <div>
            <h2 class="erp-card-title">Earlier visits by this person <span class="erp-chip erp-chip-outline">{{ $history->count() }}</span></h2>
            <p class="erp-card-sub">One record per person, one row per visit — which is what makes the reports lens worth reading.</p>
        </div>
    </div>
    <div class="erp-table-scroll">
        <table class="table erp-table">
            <thead>
                <tr><th>Day</th><th>Purpose</th><th>Host</th><th>In / out</th><th>Badge</th><th>State</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($history as $row)
                    <tr>
                        <td>{{ ($row->day() ?? $row->created_at)?->format('d M Y') }}</td>
                        <td>{{ $row->purposeLabel() }}</td>
                        <td>{{ $row->host?->name ?? '—' }}</td>
                        <td>{{ $row->checked_in_at?->format('H:i') ?? '—' }} → {{ $row->checked_out_at?->format('H:i') ?? '—' }}</td>
                        <td>{{ $row->badge_no ?? '—' }}</td>
                        <td><x-ui.status :value="$row->status" :label="$row->stateLabel()" /></td>
                        <td class="erp-td-actions"><a class="btn btn-sm btn-outline-secondary" href="{{ route('business.visitors.show', $row) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="erp-td-muted">First time through this gate.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<x-ui.related-pages />
