@php
    /* §12-15 — the provider registry: who bills us, and where their bills land. */
@endphp

<x-ui.page-header
    eyebrow="Business Management · Utility Bills · Providers"
    title="Who bills us, and where the money is booked"
    subtitle="A provider is not a supplier — nobody haggles with DESCO. It is a counterparty with a consumer number, a meter, a premises, a day of the month its bill lands, and the ledger account its bills belong in. The account is the reason this registry exists: it is what stops an electricity bill being booked as rent and being noticed only at the year end."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('business.utilities.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The desk
        </a>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('business.utilities.providers.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> Add a provider
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<x-ui.table-shell title="The registry" :count="$providers->count().' provider(s)'">
    <thead>
        <tr>
            <th>Provider</th>
            <th>Shelf</th>
            <th>Premises</th>
            <th>Bill lands on</th>
            <th>Posts to</th>
            <th class="text-end">Bills on file</th>
            <th>Status</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($providers as $provider)
            <tr>
                <td data-label="Provider">
                    <span class="erp-cell-strong">{{ $provider->name }}</span>
                    <div class="erp-td-muted">
                        {{ $provider->code }}
                        @if ($provider->consumer_no) · {{ $provider->consumer_no }} @endif
                        @if ($provider->meter_no) · meter {{ $provider->meter_no }} @endif
                    </div>
                </td>
                <td data-label="Shelf">
                    <i class="bi {{ $provider->familyIcon() }} me-1" aria-hidden="true"></i>{{ $provider->familyLabel() }}
                </td>
                <td data-label="Premises" class="erp-td-muted">{{ $provider->premises ?? '—' }}</td>
                <td data-label="Bill lands on">
                    @if ($provider->due_day)
                        <span class="erp-chip erp-chip-outline">day {{ $provider->due_day }}</span>
                    @else
                        <span class="erp-td-muted">not set</span>
                    @endif
                </td>
                <td data-label="Posts to" class="erp-td-muted">
                    {{ $provider->account?->code ?? '—' }} {{ $provider->account?->name ?? '' }}
                </td>
                <td data-label="Bills on file" class="erp-td-num text-end">{{ $provider->bills()->count() }}</td>
                <td data-label="Status">
                    <x-ui.status :value="$provider->is_active ? 'active' : 'retired'" :label="$provider->is_active ? 'Active' : 'Switched off'" />
                </td>
                <td class="erp-td-actions">
                    @if ($canManage)
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('business.utilities.providers.edit', $provider) }}">Edit</a>
                    @endif
                    <a class="btn btn-sm btn-light" href="{{ route('business.utilities.index', ['family' => $provider->family]) }}">Bills</a>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8">
                    <x-ui.empty
                        icon="bi-plug"
                        title="No providers yet"
                        text="Add the companies that bill you for power, water, gas, connectivity and rent. Each one carries the account its bills belong in, which is what keeps the expense lines honest."
                        :action="$canManage ? 'Add a provider' : null"
                        :href="$canManage ? route('business.utilities.providers.create') : null" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

@if ($providers->where('expense_account_id', null)->isNotEmpty())
    <div class="erp-note erp-note-warn mt-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            <strong>{{ $providers->where('expense_account_id', null)->count() }} provider(s) have no ledger account.</strong>
            A bill filed against one of them cannot be paid until it has somewhere to post — the desk will refuse it and say so, rather than guessing an expense line.
        </div>
    </div>
@endif

<x-ui.related-pages />
