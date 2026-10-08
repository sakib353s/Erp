@php
    /* §16-17 — the warranty register: every promise made, with the clock beside it. */
    $lenses = [
        'live' => ['label' => 'In cover', 'icon' => 'bi-shield-check', 'hint' => 'Still running today'],
        'expiring' => ['label' => 'Expiring soon', 'icon' => 'bi-hourglass-split', 'hint' => 'Within '.\App\Domain\Sales\Warranty::EXPIRING_DAYS.' days'],
        'expired' => ['label' => 'Expired', 'icon' => 'bi-shield-slash', 'hint' => 'The cover has run out'],
        'claimed' => ['label' => 'Claimed', 'icon' => 'bi-tools', 'hint' => 'Something was honoured'],
        'voided' => ['label' => 'Voided', 'icon' => 'bi-slash-circle', 'hint' => 'Withdrawn on purpose'],
    ];
    $dayLabel = fn ($date): string => $date === null ? '—' : $date->format('d M Y');
@endphp

<x-ui.page-header
    eyebrow="Sales · Warranty Register"
    title="Every promise this company made about a product, and when it runs out"
    subtitle="A warranty is a promise applied to a delivery: the dates are fixed the moment the goods reach the customer and never recomputed, so editing a product's policy tomorrow cannot shorten a cover given today. The register is read at a counter, where the only question that matters is whether the cover is still running — which is why the state you see is read from the clock beside the date rather than from a flag written by a nightly job."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('sales.warranties.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Register cover
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.policies') }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Warranty policies
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.claims') }}">
            <i class="bi bi-tools" aria-hidden="true"></i> Claims
            @if ($summary['working_claims'] > 0)
                <span class="erp-chip erp-chip-warn ms-1">{{ $summary['working_claims'] }} open</span>
            @endif
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="In cover today" :value="$summary['live']" icon="bi-shield-check" hero
              :hint="$summary['covered_qty'] > 0 ? number_format($summary['covered_qty'], 2).' unit(s) covered' : 'Nothing covered right now'"
              :href="route('sales.warranties.index', ['state' => 'live'])" />
    <x-ui.kpi label="Expiring within {{ \App\Domain\Sales\Warranty::EXPIRING_DAYS }} days"
              :value="$summary['expiring']" icon="bi-hourglass-split"
              hint="Worth telling the customer before they find out at the counter"
              :href="route('sales.warranties.index', ['state' => 'expiring'])" />
    <x-ui.kpi label="Run out" :value="$summary['expired']" icon="bi-shield-slash"
              hint="Cover that has ended — a repair now is goodwill, not warranty"
              :href="route('sales.warranties.index', ['state' => 'expired'])" />
    <x-ui.kpi label="Claims being worked" :value="$summary['working_claims']" icon="bi-tools"
              :hint="$summary['completed_claims'].' closed, '.$summary['rejected_claims'].' refused'"
              :href="route('sales.warranties.claims')" />
    <x-ui.kpi label="Open claim cost" :value="'৳'.number_format($summary['open_claim_cost'], 2)" icon="bi-cash-stack"
              hint="Recorded against claims that are not closed yet" />
    <x-ui.kpi label="Withdrawn" :value="$summary['voided']" icon="bi-slash-circle"
              hint="Voided covers — the register keeps them, and keeps the reason"
              :href="route('sales.warranties.index', ['state' => 'voided'])" />
</div>

@if ($expiring->isNotEmpty())
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            <strong>{{ $expiring->count() }} cover(s) end within {{ \App\Domain\Sales\Warranty::EXPIRING_DAYS }} days.</strong>
            A customer told today that the cover is ending is a customer who buys the extension; one who finds out
            at the counter is a customer who argues.
            {{ $expiring->take(3)->map(fn ($w) => $w->code.' ('.($w->product?->name ?? 'product').', ends '.$dayLabel($w->ends_on).')')->implode(', ') }}{{ $expiring->count() > 3 ? ', and '.($expiring->count() - 3).' more' : '' }}.
        </div>
    </div>
@endif

{{-- The five lenses over the one register. Counts come from the same service
     the KPI row above reads, so a lens can never disagree with the tile. --}}
<div class="d-flex flex-wrap gap-2 mb-3">
    @foreach ($lenses as $key => $lens)
        <a class="erp-chip {{ $state === $key ? 'erp-chip-soft' : 'erp-chip-outline' }} text-decoration-none"
           href="{{ route('sales.warranties.index', ['state' => $key]) }}"
           @if ($state === $key) aria-current="true" @endif>
            <i class="bi {{ $lens['icon'] }}" aria-hidden="true"></i>
            {{ $lens['label'] }} <strong>{{ $summary[$key] ?? 0 }}</strong>
            <span class="erp-td-muted d-none d-md-inline">· {{ $lens['hint'] }}</span>
        </a>
    @endforeach
</div>

<form class="erp-filterbar" method="GET" action="{{ route('sales.warranties.index') }}">
    <input type="hidden" name="state" value="{{ $state }}">
    <div class="erp-filter">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ request('q') }}"
               placeholder="WR-000123, serial, product or customer">
    </div>
    <div class="erp-filter">
        <label class="form-label" for="product">Product</label>
        <select class="form-select" name="product" id="product" data-erp-autosubmit>
            <option value="">Every product</option>
            @foreach ($products as $product)
                <option value="{{ $product->id }}" @selected((int) request('product') === $product->id)>
                    {{ $product->name }} ({{ $product->code }})
                </option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="customer">Customer</label>
        <select class="form-select" name="customer" id="customer" data-erp-autosubmit>
            <option value="">Every customer</option>
            @foreach ($customers as $customer)
                <option value="{{ $customer->id }}" @selected((int) request('customer') === $customer->id)>{{ $customer->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('sales.warranties.index', ['state' => $state]) }}">Reset</a>
    </div>
</form>

<x-ui.table-shell :title="$lenses[$state]['label'].' warranties'" :count="$warranties->total().' cover(s)'">
    <thead>
        <tr>
            <th>Warranty</th>
            <th>Product</th>
            <th>Customer</th>
            <th>Cover</th>
            <th>Ends</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($warranties as $warranty)
            <tr>
                <td data-label="Warranty">
                    <a class="erp-cell-strong" href="{{ route('sales.warranties.show', $warranty) }}">{{ $warranty->code }}</a>
                    <div class="erp-td-muted">
                        {{ ucfirst($warranty->source) }} · {{ $warranty->months }} month(s)
                        @if ($warranty->serial_no)
                            · serial {{ $warranty->serial_no }}
                        @endif
                    </div>
                </td>
                <td data-label="Product">
                    {{ $warranty->product?->name ?? '—' }}
                    <div class="erp-td-muted">{{ $warranty->product?->code }} · qty {{ rtrim(rtrim(number_format((float) $warranty->qty, 4), '0'), '.') }}</div>
                </td>
                <td data-label="Customer">{{ $warranty->customer?->name ?? 'Counter / unknown' }}</td>
                <td data-label="Cover">
                    {{ $dayLabel($warranty->starts_on) }} → {{ $dayLabel($warranty->ends_on) }}
                    <div class="erp-td-muted">{{ $warranty->daysRemaining() }} day(s) left</div>
                </td>
                <td data-label="Ends">
                    @php($days = $warranty->daysRemaining())
                    <span class="{{ $days < 0 ? 'text-danger' : ($warranty->expiresSoon() ? 'text-warning' : '') }}">
                        {{ $days < 0 ? abs($days).' day(s) ago' : $days.' day(s)' }}
                    </span>
                </td>
                <td data-label="State">
                    <x-ui.status :value="$warranty->stateNow()" :label="$warranty->stateLabel()" />
                </td>
                <td class="text-end">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.warranties.show', $warranty) }}">Open</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7">
                    <x-ui.empty
                        icon="bi-shield-check"
                        :title="'No cover in the '.$lenses[$state]['label'].' lens'"
                        text="Cover is created by itself when an order is delivered — for any product that has a warranty policy. Nothing here usually means no policy has been set yet."
                        :action="$canManage ? 'Open the policy desk' : null"
                        :href="$canManage ? route('sales.warranties.policies') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($warranties->hasPages())
        <x-slot:footer>{{ $warranties->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
