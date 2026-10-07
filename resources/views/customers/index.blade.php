@extends('layouts.app')

@section('page_title', 'Customers')

@section('content')
    <x-ui.page-header
        eyebrow="Sales & CRM"
        title="Customers"
        subtitle="Every party you sell to, with the money they owe derived from the ledgers — never from a hand-edited due column."
        :pin="true">
        <x-slot:actions>
            @if ($perm('customers.export'))
                <a class="btn btn-outline-secondary" href="{{ route('customers.export', request()->query()) }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Export CSV
                </a>
            @endif
            @if ($perm('customers.due.view'))
                <a class="btn btn-outline-secondary" href="{{ route('customers.due') }}">
                    <i class="bi bi-alarm" aria-hidden="true"></i> Due &amp; ageing
                </a>
            @endif
            @if ($perm('customers.groups'))
                <a class="btn btn-outline-secondary" href="{{ route('customers.groups') }}">
                    <i class="bi bi-collection" aria-hidden="true"></i> Groups
                </a>
            @endif
            @if ($perm('customers.create'))
                <a class="btn btn-primary" href="{{ route('customers.create') }}">
                    <i class="bi bi-person-plus" aria-hidden="true"></i> New customer
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Customers" :value="number_format($summary['total'])" icon="bi-people" hint="Matching the filters below" />
        <x-ui.kpi label="Receivable on this page" value="৳ {{ number_format($summary['due'], 2) }}" icon="bi-cash-coin" hint="Issued invoices minus posted receipts" />
        <x-ui.kpi label="Overdue" value="৳ {{ number_format($summary['overdue'], 2) }}" icon="bi-alarm" hint="Past the credit date" />
        <x-ui.kpi label="Beyond credit limit" :value="number_format($summary['over_limit'])" icon="bi-shield-exclamation" hint="Customer with exposure over their approved limit" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('customers.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Name, code, phone or e-mail…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="group">Group</label>
            <select class="form-select" id="group" name="group">
                <option value="">All groups</option>
                @foreach ($groups as $group)
                    <option value="{{ $group->id }}" @selected((int) $filters['group'] === $group->id)>{{ $group->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="district">District</label>
            <select class="form-select" id="district" name="district">
                <option value="">All districts</option>
                @foreach ($districts as $district)
                    <option value="{{ $district->id }}" @selected((int) $filters['district'] === $district->id)>{{ $district->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any</option>
                <option value="blacklisted" @selected($filters['status'] === 'blacklisted')>Blacklisted</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                <option value="over_limit" @selected($filters['status'] === 'over_limit')>Over credit limit</option>
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="sort">Sort</label>
            <select class="form-select" id="sort" name="sort">
                <option value="">Name (A→Z)</option>
                <option value="name_desc" @selected($filters['sort'] === 'name_desc')>Name (Z→A)</option>
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
                <option value="code" @selected($filters['sort'] === 'code')>Customer code</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('customers.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @if ($filters['status'] === 'over_limit')
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
            <div>
                <strong>Showing customers past their approved credit limit.</strong>
                Exposure is the open invoice balance; raise the limit from the customer profile when the relationship justifies it — the change is recorded with a reason.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="$customers->total().' customers'">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Contact</th>
                <th>Group</th>
                <th class="erp-th-num">Credit limit</th>
                <th class="erp-th-num">Due</th>
                <th class="erp-th-num">Overdue</th>
                <th>Status</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($customers as $customer)
                @php($money = $receivables[$customer->id] ?? ['due' => 0, 'overdue' => 0])
                <tr>
                    <td data-label="Customer">
                        <a class="erp-row-link" href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a>
                        <span class="erp-td-muted d-block small">{{ $customer->code }}
                            @if ($customer->type === 'business') · business @endif</span>
                    </td>
                    <td data-label="Contact" class="erp-td-muted">
                        {{ $customer->phone ?? '—' }}
                        @if ($customer->district) <span class="d-block small">{{ $customer->district->name }}</span>@endif
                    </td>
                    <td data-label="Group">
                        @if ($customer->group)
                            <span class="erp-chip erp-chip-soft">{{ $customer->group->name }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Credit limit" class="erp-td-num">
                        @if ((float) $customer->credit_limit > 0)
                            ৳ {{ number_format((float) $customer->credit_limit, 2) }}
                            @if ($customer->credit_days > 0)<span class="erp-td-muted d-block small">{{ $customer->credit_days }} days</span>@endif
                        @else
                            <span class="erp-td-muted">Cash only</span>
                        @endif
                    </td>
                    <td data-label="Due" class="erp-td-num {{ (float) $money['due'] > 0 ? '' : 'erp-td-muted' }}">
                        ৳ {{ number_format((float) $money['due'], 2) }}
                    </td>
                    <td data-label="Overdue" class="erp-td-num">
                        @if ((float) $money['overdue'] > 0)
                            <span class="erp-amount erp-amount-danger">৳ {{ number_format((float) $money['overdue'], 2) }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Status">
                        <x-ui.status :value="$customer->status()" />
                    </td>
                    <td data-label="Open" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.show', $customer) }}">Profile</a>
                        @if ($perm('accounting.ledger.view'))
                            <a class="btn btn-sm btn-light" href="{{ route('customers.ledger', $customer) }}">Ledger</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="p-0">
                        <x-ui.empty
                            icon="bi-person-plus"
                            title="No customers match these filters"
                            text="Widen the search, clear the group filter, or add the party you just met."
                            :action="$perm('customers.create') ? 'Add a customer' : null"
                            :href="$perm('customers.create') ? route('customers.create') : null" />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <span>{{ $customers->total() }} customer{{ $customers->total() === 1 ? '' : 's' }}</span>
            {{ $customers->links() }}
        </x-slot:footer>
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
