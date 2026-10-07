@extends('layouts.app')

@section('page_title', 'Courier Partners')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Courier Partners</h1>
            <p class="erp-page-sub">Integration truth lives on each row — a courier is only ever dispatched when it is configured AND enabled; the screen reports exactly that, never a claimed connection test.</p>
        </div>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">{{ $editCourier !== null ? 'Edit courier: '.$editCourier->code : 'Add courier' }}</h2>
        <form method="POST" action="{{ route('couriers.store') }}">
            @csrf
            @if ($editCourier !== null)
                <input type="hidden" name="id" value="{{ $editCourier->id }}">
            @endif

            <div class="row g-2 mb-2">
                <div class="col-md-2">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" maxlength="32" required
                           pattern="[A-Za-z0-9_-]+"
                           value="{{ old('code', $editCourier?->code) }}" placeholder="PATHAO">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" maxlength="64" required
                           value="{{ old('name', $editCourier?->name) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" maxlength="255"
                           value="{{ old('description', $editCourier?->description) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="tracking_url_pattern">Tracking URL pattern</label>
                    <input class="form-control" id="tracking_url_pattern" name="tracking_url_pattern" maxlength="255"
                           value="{{ old('tracking_url_pattern', $editCourier?->tracking_url_pattern) }}"
                           placeholder="https://track.example/{external_ref}">
                </div>
            </div>

            <div class="row g-2 mb-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="configuration_status">Configuration status</label>
                    <select class="form-select" id="configuration_status" name="configuration_status" required>
                        <option value="not_configured"
                            @selected(old('configuration_status', $editCourier?->configuration_status ?? 'not_configured') === 'not_configured')>
                            not_configured
                        </option>
                        <option value="configured"
                            @selected(old('configuration_status', $editCourier?->configuration_status) === 'configured')>
                            configured
                        </option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="integration_enabled" name="integration_enabled"
                               value="1" @checked(old('integration_enabled', $editCourier?->integration_enabled))>
                        <label class="form-check-label" for="integration_enabled">Integration enabled</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                               value="1" @checked(old('is_active', $editCourier?->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="webhook_secret">Webhook signing secret</label>
                    <input class="form-control" id="webhook_secret" name="webhook_secret" type="password"
                           minlength="8" maxlength="128" autocomplete="new-password"
                           placeholder="{{ $editCourier?->webhook_secret !== null ? 'Secret set — leave blank to keep it' : '8+ characters' }}">
                    <div class="form-text">
                        @if ($editCourier?->webhook_secret !== null)
                            A signing secret is stored (encrypted). It is never displayed again.
                        @else
                            No signing secret yet — tracking webhooks cannot be verified without one.
                        @endif
                    </div>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-primary" type="submit">{{ $editCourier !== null ? 'Save changes' : 'Create courier' }}</button>
                </div>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Configuration</th>
                        <th>Integration</th>
                        <th>Webhook secret</th>
                        <th class="text-end">Shipments</th>
                        <th>Active</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($couriers as $courier)
                        <tr>
                            <td><code>{{ $courier->code }}</code></td>
                            <td>
                                {{ $courier->name }}
                                @if ($courier->description)
                                    <div class="small text-muted">{{ $courier->description }}</div>
                                @endif
                            </td>
                            <td>
                                <span class="erp-status {{ $courier->configuration_status === 'configured' ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $courier->configuration_status === 'configured' ? 'configured' : 'not configured' }}
                                </span>
                            </td>
                            <td>
                                @if ($courier->integration_enabled)
                                    {{ $courier->configuration_status === 'configured' ? 'enabled' : 'enabled (still not configured)' }}
                                @else
                                    <span class="text-muted">disabled</span>
                                @endif
                            </td>
                            <td>
                                @if ($courier->webhook_secret !== null)
                                    <span class="erp-status erp-status-pending">set</span>
                                @else
                                    <span class="text-muted">none</span>
                                @endif
                            </td>
                            <td class="text-end">{{ $courier->shipments_count }}</td>
                            <td>
                                <span class="erp-status {{ $courier->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                                    {{ $courier->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                            <td class="text-end">
                                @isset($providerSlugs[$courier->id])
                                    <a class="btn btn-sm btn-outline-primary"
                                       href="{{ route('couriers.provider.show', $providerSlugs[$courier->id]) }}">Provider</a>
                                @endisset
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="{{ route('couriers.index', ['edit' => $courier->id]) }}">Edit</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">No couriers yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
