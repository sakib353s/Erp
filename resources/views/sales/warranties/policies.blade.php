@php
    /* §16-16 — what the company promises, per product. */
    $coverage = $stocked > 0 ? round($covered / $stocked * 100) : 0;
@endphp

<x-ui.page-header
    eyebrow="Sales · Warranty Policies"
    title="What this company promises about each product it sells"
    subtitle="A policy is not a marketing line — it is the sentence a customer can hold the company to, which is why the register refuses to cover a product nobody promised anything about. There is no default: a product with no row here has no cover, and the desk says so rather than inventing twelve months on the company's behalf."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.index') }}">
            <i class="bi bi-shield-check" aria-hidden="true"></i> Register
        </a>
        @if ($canManage && $withoutPolicy->isNotEmpty())
            <a class="btn btn-outline-secondary" href="#set-policy">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Set a policy
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Products with a policy" :value="$covered" icon="bi-clipboard-check" hero
              :hint="$coverage.'% of the '.$stocked.' stocked product(s)'" />
    <x-ui.kpi label="Stocked without a policy" :value="$withoutPolicyCount" icon="bi-clipboard-x"
              hint="Sold with no cover — the customer's expectation is whatever the salesperson said" />
    <x-ui.kpi label="Longest cover allowed" :value="$maxMonths.' months'" icon="bi-calendar-range"
              hint="Anything longer is far more likely a typo than a promise" />
</div>

@if ($withoutPolicyCount > 0)
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-clipboard-x" aria-hidden="true"></i>
        <div>
            <strong>{{ $withoutPolicyCount }} stocked product(s) carry no warranty policy.</strong>
            Deliveries of these products create no cover — nothing is wrong with the register, the company simply
            never promised anything. If the invoice or the box says otherwise, set the policy here so the promise
            starts being recorded{{ $withoutPolicy->isNotEmpty() ? ': '.$withoutPolicy->take(4)->map(fn ($p) => $p->name)->implode(', ') : '' }}{{ $withoutPolicyCount > 4 ? ', and more' : '' }}.
        </div>
    </div>
@endif

@if ($canManage)
    <section class="collapse {{ $withoutPolicy->isNotEmpty() ? '' : '' }}" id="set-policy">
        <div class="erp-card mb-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Set or replace a policy</h2>
                    <p class="erp-card-sub">A product has one policy, not a stack: storing it again replaces it. Covers already granted keep the dates they were granted with.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                <form class="erp-form-grid" method="POST" action="{{ route('sales.warranties.policies.store') }}"
                      data-confirm="Save this warranty policy? Covers already granted keep the dates they were granted with.">
                    @csrf
                    <div class="erp-filter">
                        <label class="form-label" for="policy-product">Product</label>
                        <select class="form-select" name="product_id" id="policy-product" required>
                            <option value="">Choose a product…</option>
                            @foreach ($choices as $product)
                                <option value="{{ $product->id }}" @selected((int) old('product_id') === $product->id)>
                                    {{ $product->name }} ({{ $product->code }})@if ($product->warrantyPolicy === null) — no policy yet @else — currently {{ $product->warrantyPolicy->months }} month(s) @endif
                                </option>
                            @endforeach
                        </select>
                        <small class="erp-td-muted">Stocked products, with the cover each one carries today.</small>
                    </div>
                    <div class="erp-filter">
                        <label class="form-label" for="months">Months of cover</label>
                        <input class="form-control" type="number" name="months" id="months" min="1" max="{{ $maxMonths }}" value="12" required>
                    </div>
                    <div class="erp-filter">
                        <label class="form-label" for="kind">Kind</label>
                        <select class="form-select" name="kind" id="kind" required>
                            @foreach ($kinds as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="erp-filter">
                        <label class="form-label" for="terms">The terms, if any</label>
                        <input class="form-control" type="text" name="terms" id="terms" maxlength="500"
                               placeholder="e.g. excludes water damage and physical breakage">
                    </div>
                    <div class="erp-check-grid">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="covers_parts" value="1" checked>
                            <span class="form-check-label">Covers parts</span>
                        </label>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="covers_labour" value="1" checked>
                            <span class="form-check-label">Covers labour</span>
                        </label>
                    </div>
                    <div class="erp-form-actions">
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> Save policy
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('sales.warranties.policies') }}">
    <div class="erp-filter">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ request('q') }}" placeholder="Product name or code">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        <a class="btn btn-link" href="{{ route('sales.warranties.policies') }}">Reset</a>
    </div>
</form>

<x-ui.table-shell title="Product warranty policies" :count="$policies->total().' policy row(s)'">
    <thead>
        <tr>
            <th>Product</th>
            <th>Cover</th>
            <th>Includes</th>
            <th>Terms</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($policies as $policy)
            <tr>
                <td data-label="Product">
                    <span class="erp-cell-strong">{{ $policy->product?->name ?? '—' }}</span>
                    <div class="erp-td-muted">{{ $policy->product?->code }}</div>
                </td>
                <td data-label="Cover">
                    {{ $policy->months }} month(s)
                    <div class="erp-td-muted">{{ $policy->kindLabel() }}</div>
                </td>
                <td data-label="Includes">{{ $policy->coverLabel() }}</td>
                <td data-label="Terms">{{ $policy->terms ?? '—' }}</td>
                <td data-label="State">
                    <x-ui.status :value="$policy->is_active ? 'active' : 'inactive'"
                                 :label="$policy->is_active ? 'Covering new deliveries' : 'Not applied'" />
                    @if ($policy->updater)
                        <div class="erp-td-muted">by {{ $policy->updater->name }}</div>
                    @endif
                </td>
                <td class="text-end">
                    @if ($canManage)
                        <a class="btn btn-sm btn-outline-secondary"
                           href="{{ route('sales.warranties.policies', ['q' => $policy->product?->code]) }}">
                            Open
                        </a>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty icon="bi-clipboard-check" title="No product carries a warranty policy yet"
                                text="Until one does, delivering goods creates no cover at all — and the register will say so honestly rather than inventing a period." />
                </td>
            </tr>
        @endforelse
    </tbody>
    @if ($policies->hasPages())
        <x-slot:footer>{{ $policies->links() }}</x-slot:footer>
    @endif
</x-ui.table-shell>

<x-ui.related-pages />
