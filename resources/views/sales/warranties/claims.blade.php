@php
    /* §16-18 — the claim queue: what came back, and who is holding it up. */
    $dayLabel = fn ($date): string => $date === null ? '—' : $date->format('d M Y');
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
    $age = fn ($claim): int => (int) \Carbon\Carbon::parse($claim->reported_on)->startOfDay()
        ->diffInDays(now()->startOfDay(), false);
    $lenses = [
        'working' => 'Being worked',
        'completed' => 'Closed',
        'rejected' => 'Refused',
        'all' => 'Everything',
    ];
@endphp

<x-ui.page-header
    eyebrow="Sales · Warranty Claims"
    title="What came back, what was done about it, and who decided"
    subtitle="A claim is an event on a cover, never an edit to it: the promise stays where it was made, and the claim records the fault, the decision and the cost beside it. Closing one means saying what was done — repaired, replaced, refunded — because a claim that ended without a decision is a claim nobody can be asked about later."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.index') }}">
            <i class="bi bi-shield-check" aria-hidden="true"></i> Register
        </a>
        @if ($canManage)
            <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.policies') }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Policies
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Being worked" :value="$summary['working_claims']" icon="bi-tools" hero
              hint="Reported and not yet closed — the queue that ages" />
    <x-ui.kpi label="Closed" :value="$summary['completed_claims']" icon="bi-check2-circle"
              hint="Honoured, with a resolution recorded against them" />
    <x-ui.kpi label="Refused" :value="$summary['rejected_claims']" icon="bi-x-circle"
              hint="Not covered — kept, because “we already said no” is worth being able to prove" />
    <x-ui.kpi label="Cost on open claims" :value="$money($summary['open_claim_cost'])" icon="bi-cash-stack"
              hint="Recorded against claims that have not been closed yet" />
    <x-ui.kpi label="Covers in force" :value="$summary['live']" icon="bi-shield-check"
              :hint="$summary['expiring'].' end within '.\App\Domain\Sales\Warranty::EXPIRING_DAYS.' days'"
              :href="route('sales.warranties.index', ['state' => 'live'])" />
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    @foreach ($lenses as $key => $label)
        <a class="erp-chip {{ $status === $key ? 'erp-chip-soft' : 'erp-chip-outline' }} text-decoration-none"
           href="{{ route('sales.warranties.claims', ['status' => $key]) }}"
           @if ($status === $key) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
</div>

<form class="erp-filterbar" method="GET" action="{{ route('sales.warranties.claims') }}">
    <input type="hidden" name="status" value="{{ $status }}">
    <div class="erp-filter">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ request('q') }}"
               placeholder="WC-000123, WR-000123, serial, product or fault">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('sales.warranties.claims', ['status' => $status]) }}">Reset</a>
    </div>
</form>

@if ($claims->isNotEmpty() && $claims->getCollection()->contains(fn ($claim) => $claim->isOpen() && $age($claim) > 14))
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            <strong>Some claims have been open for more than two weeks.</strong>
            A customer waiting on a decision cannot plan around it, and the longer a claim sits the harder the fault is
            to prove — the unit may have moved, been repaired elsewhere, or been thrown away.
        </div>
    </div>
@endif

<x-ui.table-shell title="{{ $lenses[$status] ?? ucfirst($status) }} claims" :count="$claims->total().' claim(s)'">
    <thead>
        <tr>
            <th>Claim</th>
            <th>Cover</th>
            <th>Customer</th>
            <th>Reported</th>
            <th>State</th>
            <th class="text-end">Cost</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($claims as $claim)
            <tr>
                <td data-label="Claim">
                    <span class="erp-cell-strong">{{ $claim->code }}</span>
                    <div class="erp-td-muted">{{ $claim->fault }}</div>
                </td>
                <td data-label="Cover">
                    @if ($claim->warranty)
                        <a href="{{ route('sales.warranties.show', $claim->warranty) }}">{{ $claim->warranty->code }}</a>
                        <div class="erp-td-muted">
                            {{ $claim->warranty->product?->name }}
                            @if ($claim->warranty->serial_no) · serial {{ $claim->warranty->serial_no }} @endif
                        </div>
                    @else
                        —
                    @endif
                </td>
                <td data-label="Customer">{{ $claim->warranty?->customer?->name ?? 'Counter / unknown' }}</td>
                <td data-label="Reported">
                    {{ $dayLabel($claim->reported_on) }}
                    <div class="erp-td-muted">
                        @if ($claim->isOpen())
                            @php($days = $age($claim))
                            open {{ $days }} day(s)
                        @else
                            closed {{ $dayLabel($claim->resolved_on) }}
                        @endif
                    </div>
                </td>
                <td data-label="State">
                    <x-ui.status :value="$claim->status" :label="$claim->statusLabel()" />
                    @if ($claim->resolution)
                        <div class="erp-td-muted">{{ $claim->resolutionLabel() }}</div>
                    @endif
                </td>
                <td data-label="Cost" class="text-end">{{ $money($claim->cost) }}</td>
                <td class="text-end">
                    @if ($claim->warranty)
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.warranties.show', $claim->warranty) }}#raise-claim">Open cover</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty icon="bi-tools" title="{{ $status === 'working' ? 'Nothing is waiting on anybody' : 'No claims in this lens' }}"
                                text="Claims are raised from the cover they belong to — open the register, find the unit, and record what the customer reported." />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($claims->hasPages())
        <x-slot:footer>{{ $claims->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
