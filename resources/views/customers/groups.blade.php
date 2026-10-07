@extends('layouts.app')

@section('page_title', 'Customer groups')

@section('content')
    <x-ui.page-header
        eyebrow="Sales & CRM"
        title="Customer groups"
        subtitle="A group is a pricing and discount lane: rules target the group, customers inherit it. Membership is set on the customer record."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-light" href="{{ route('customers.index') }}">All customers</a>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($perm('pricing.rules'))
        <div class="erp-note mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                Group pricing is configured in <a href="{{ route('pricing.rules.index') }}">Pricing rules</a> —
                a rule can target one customer group and win over list price automatically at billing.
            </div>
        </div>
    @endif

    <x-ui.table-shell title="Groups" :count="$groups->count().' groups'">
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Description</th>
                <th class="erp-th-num">Customers</th>
                <th class="erp-th-num">Pricing rules</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($groups as $group)
                <tr>
                    <td data-label="Code"><code>{{ $group->code }}</code></td>
                    <td data-label="Name"><span class="erp-row-link">{{ $group->name }}</span></td>
                    <td data-label="Description" class="erp-td-muted">{{ $group->description ?: '—' }}</td>
                    <td data-label="Customers" class="erp-td-num">
                        <a href="{{ route('customers.index', ['group' => $group->id]) }}">{{ $group->customers_count }}</a>
                    </td>
                    <td data-label="Rules" class="erp-td-num">{{ $rules[$group->id] ?? 0 }}</td>
                    <td data-label="Status"><x-ui.status :value="$group->is_active ? 'active' : 'inactive'" /></td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="p-0">
                        <x-ui.empty
                            icon="bi-collection"
                            title="No groups yet"
                            text="Create a group such as Retailer, Wholesaler or Corporate to drive both pricing and reporting." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($perm('customers.groups'))
        <section class="erp-card mt-3">
            <h2 class="erp-card-title mb-3">New group</h2>
            <form method="POST" action="{{ route('customers.groups.store') }}">
                @csrf
                <div class="row g-2 align-items-end">
                    <div class="col-sm-2">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control @error('code') is-invalid @enderror" id="code" name="code" required maxlength="32" placeholder="RET">
                        @error('code')<span class="erp-field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" required maxlength="128" placeholder="Retailer">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="description">Description</label>
                        <input class="form-control" id="description" name="description" maxlength="500">
                    </div>
                    <div class="col-sm-2">
                        <button class="btn btn-primary w-100" type="submit">Create group</button>
                    </div>
                </div>
            </form>
        </section>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
