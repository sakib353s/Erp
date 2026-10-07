@extends('layouts.app')

@section('page_title', 'Pricing Rules')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Pricing Rules</h1>
            <p class="erp-page-sub">Deterministic cascade: customer group → quantity break → geographic → time-based → special — each stage updates the running price; special wins over them all.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('pricing.rules.create') }}">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> Add rule
        </a>
    </div>

    <ul class="nav nav-pills mb-3">
        <li class="nav-item">
            <a class="nav-link {{ $type === null ? 'active' : '' }}" href="{{ route('pricing.rules.index') }}">All</a>
        </li>
        @foreach (\App\Domain\Masters\PricingRule::TYPES as $t)
            <li class="nav-item">
                <a class="nav-link {{ $type === $t ? 'active' : '' }}"
                   href="{{ route('pricing.rules.index', ['type' => $t]) }}">{{ str_replace('_', ' ', ucfirst($t)) }}</a>
            </li>
        @endforeach
    </ul>

    <div class="erp-card mb-3">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Stage</th>
                        <th>Scope</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Priority</th>
                        <th>Window</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rules as $rule)
                        <tr>
                            <td class="fw-semibold">
                                {{ $rule->name }}
                                @if ($rule->hasPendingApproval())
                                    <span class="badge text-bg-warning">pending override</span>
                                @endif
                            </td>
                            <td><span class="badge text-bg-secondary">{{ str_replace('_', ' ', $rule->rule_type) }}</span></td>
                            <td class="small">
                                @php
                                    $scope = match ($rule->rule_type) {
                                        'special' => 'Customer '.($rule->customer?->code ?? '—'),
                                        'customer_group' => 'Group '.($rule->customerGroup?->code ?? '—'),
                                        'quantity_break' => 'Qty '.($rule->qty_min ?? 1).'–'.($rule->qty_max ?? 'any'),
                                        'geographic' => 'Zone '.($rule->deliveryZone?->code ?? '—'),
                                        default => 'Always in effect',
                                    };
                                    $extra = [];
                                    if ($rule->product) { $extra[] = 'Product '.$rule->product->code; }
                                    if ($rule->product_category_id) { $extra[] = 'Category #'.$rule->product_category_id; }
                                    if ($rule->priceList) { $extra[] = 'List '.$rule->priceList->code; }
                                @endphp
                                {{ $scope }}
                                @if ($extra !== [])
                                    <div class="text-muted">{{ implode(' · ', $extra) }}</div>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($rule->price !== null)
                                    <span class="fw-semibold">{{ number_format((float) $rule->price, 2) }}</span>
                                @else
                                    −{{ number_format((float) $rule->percent_off, 2) }}%
                                @endif
                            </td>
                            <td>
                                <form method="POST" action="{{ route('pricing.rules.priority', $rule) }}"
                                      class="d-flex gap-1 justify-content-end">
                                    @csrf
                                    @method('PATCH')
                                    <input type="number" name="priority" min="0" max="9999"
                                           value="{{ $rule->priority }}"
                                           class="form-control form-control-sm" style="width: 5.5rem"
                                           aria-label="Priority for {{ $rule->name }}"
                                           @disabled($rule->hasPendingApproval())>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit"
                                            @disabled($rule->hasPendingApproval())>Set</button>
                                </form>
                            </td>
                            <td class="small">
                                {{ $rule->valid_from?->toDateString() ?? '…' }} → {{ $rule->valid_to?->toDateString() ?? '…' }}
                                @if ($rule->time_from || $rule->time_to)
                                    <div class="text-muted">{{ $rule->time_from ?? '00:00' }}–{{ $rule->time_to ?? '24:00' }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="erp-status {{ $rule->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $rule->is_active ? 'active' : 'disabled' }}
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <form method="POST" action="{{ route('pricing.rules.status', $rule) }}">
                                        @csrf
                                        @method('PATCH')
                                        <button class="btn btn-sm {{ $rule->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                                type="submit" @disabled($rule->hasPendingApproval())>
                                            {{ $rule->is_active ? 'Disable' : 'Enable' }}
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="{{ route('pricing.rules.edit', $rule) }}">Edit</a>
                                    <form method="POST" action="{{ route('pricing.rules.destroy', $rule) }}"
                                          onsubmit="return confirm('Delete rule {{ $rule->name }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No pricing rules yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $rules->links() }}</div>
    </div>

    <div class="erp-card" id="groups">
        <h2 class="erp-h3">Customer groups</h2>
        <p class="text-muted small mb-3">Groups customers into pricing tiers; assign a customer to a group to make customer-group rules apply.</p>

        <div class="table-responsive mb-3">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th class="text-end">Customers</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($groups as $group)
                        <tr>
                            <td><code>{{ $group->code }}</code></td>
                            <td>{{ $group->name }}</td>
                            <td class="text-end">{{ $group->customers_count }}</td>
                            <td class="text-end">
                                @if ($group->customers_count === 0)
                                    <form method="POST" action="{{ route('pricing.customer-groups.destroy', $group) }}"
                                          onsubmit="return confirm('Delete group {{ $group->code }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                    </form>
                                @else
                                    <span class="text-muted small">in use</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-3">No customer groups yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <form method="POST" action="{{ route('pricing.customer-groups.store') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-md-2">
                <label class="form-label" for="group_code">Code</label>
                <input class="form-control" id="group_code" name="code" value="{{ old('code') }}" maxlength="32" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="group_name">Name</label>
                <input class="form-control" id="group_name" name="name" value="{{ old('name') }}" maxlength="120" required>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="group_description">Description</label>
                <input class="form-control" id="group_description" name="description" value="{{ old('description') }}" maxlength="500">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Add group</button>
            </div>
            @if ($errors->has('code'))
                <div class="col-12 text-danger small">{{ $errors->first('code') }}</div>
            @endif
            @if ($errors->has('group'))
                <div class="col-12 text-danger small">{{ $errors->first('group') }}</div>
            @endif
        </form>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
