@extends('layouts.app')

@section('page_title', $adapter->label())

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $adapter->label() }} <span class="text-muted fs-6"><code>{{ $adapter->code() }}</code></span></h1>
            <p class="erp-page-sub">Provider adapter for create-shipment, rates, track and webhook — every capability reports its honest current state. No connection test is offered because no provider API is called from here.</p>
            <a class="small" href="{{ route('couriers.index') }}">&larr; Courier Partners</a>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Current state</h2>
                @if ($courier === null)
                    <div class="alert alert-secondary py-2">
                        No configuration row yet — this provider's courier row is created when you save the form.
                    </div>
                @endif
                <dl class="row mb-0">
                    <dt class="col-sm-5">Configuration</dt>
                    <dd class="col-sm-7">
                        <span class="erp-status {{ ($courier?->configuration_status ?? 'not_configured') === 'configured' ? 'erp-status-active' : 'erp-status-disabled' }}">
                            {{ $courier?->configuration_status ?? 'not_configured' }}
                        </span>
                    </dd>
                    <dt class="col-sm-5">Integration</dt>
                    <dd class="col-sm-7">
                        @if ($courier?->integration_enabled)
                            {{ $courier->configuration_status === 'configured' ? 'enabled' : 'enabled (still not configured)' }}
                        @else
                            <span class="text-muted">disabled</span>
                        @endif
                    </dd>
                    <dt class="col-sm-5">Webhook secret</dt>
                    <dd class="col-sm-7">
                        @if ($courier?->webhook_secret !== null)
                            <span class="erp-status erp-status-pending">set</span>
                        @else
                            <span class="text-muted">none — webhooks cannot be verified</span>
                        @endif
                    </dd>
                    <dt class="col-sm-5">Active</dt>
                    <dd class="col-sm-7">
                        <span class="erp-status {{ ($courier?->is_active ?? true) ? 'erp-status-active' : 'erp-status-disabled' }}">
                            {{ ($courier?->is_active ?? true) ? 'active' : 'inactive' }}
                        </span>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Capabilities</h2>
                <ul class="mb-0">
                    <li><strong>Create-shipment</strong> — configured + enabled yields the <code>{{ $adapter->code() }}-{ORDER_NO}</code> consignment ref (plus the tracking URL below when a pattern is set); otherwise the assignment stays local (<code>pending_dispatch</code>) with no external reference.</li>
                    <li><strong>Rates</strong> — quoted only from your active delivery zones (base + per-kg + weight slab). The response always says <code>delivery_zone</code> as the source; no provider price API is called or invented.</li>
                    <li><strong>Track</strong> — maps your locally recorded tracking events into this provider's response shape. No history is ever fabricated.</li>
                    <li><strong>Webhook</strong> — HMAC-signed <code>POST /webhooks/couriers/{id}</code> events are translated through the vocabulary below; codes outside it are rejected (422), never guessed.</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Configuration</h2>
                <form method="POST" action="{{ route('couriers.provider.update', $slug) }}">
                    @csrf
                    @method('PUT')

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label" for="name">Name</label>
                            <input class="form-control" id="name" name="name" maxlength="64"
                                   value="{{ old('name', $formCourier->name) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="description">Description</label>
                            <input class="form-control" id="description" name="description" maxlength="255"
                                   value="{{ old('description', $formCourier->description) }}">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <label class="form-label" for="configuration_status">Configuration status</label>
                            <select class="form-select" id="configuration_status" name="configuration_status" required>
                                <option value="not_configured"
                                    @selected(old('configuration_status', $formCourier->configuration_status ?? 'not_configured') === 'not_configured')>
                                    not_configured
                                </option>
                                <option value="configured"
                                    @selected(old('configuration_status', $formCourier->configuration_status) === 'configured')>
                                    configured
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="tracking_url_pattern">Tracking URL pattern</label>
                            <input class="form-control" id="tracking_url_pattern" name="tracking_url_pattern" maxlength="255"
                                   value="{{ old('tracking_url_pattern', $formCourier->tracking_url_pattern) }}"
                                   placeholder="https://track.example/{external_ref}">
                        </div>
                    </div>

                    <div class="row g-2 mb-2">
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="integration_enabled" name="integration_enabled"
                                       value="1" @checked(old('integration_enabled', $formCourier->integration_enabled))>
                                <label class="form-check-label" for="integration_enabled">Integration enabled</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                       value="1" @checked(old('is_active', $formCourier->is_active ?? true))>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="webhook_secret">Webhook signing secret</label>
                            <input class="form-control" id="webhook_secret" name="webhook_secret" type="password"
                                   minlength="8" maxlength="128" autocomplete="new-password"
                                   placeholder="{{ $formCourier->webhook_secret !== null ? 'Secret set — leave blank to keep it' : '8+ characters' }}">
                            <div class="form-text">
                                @if ($formCourier->webhook_secret !== null)
                                    Stored encrypted; never displayed again.
                                @else
                                    Required before this provider's webhooks can be verified.
                                @endif
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit">Save configuration</button>
                </form>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="erp-card h-100">
                <h2 class="erp-h3">Rate probe <small class="text-muted">(local zones only)</small></h2>
                <form method="GET" action="{{ route('couriers.provider.show', $slug) }}" class="row g-2 mb-3">
                    <div class="col-md-7">
                        <label class="form-label" for="district_id">Destination district</label>
                        <select class="form-select" id="district_id" name="district_id" required>
                            <option value="">— choose a district —</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->id }}" @selected($probeDistrict === $district->id)>{{ $district->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="weight_kg">Weight (kg)</label>
                        <input class="form-control" id="weight_kg" name="weight_kg" type="number" min="0" step="0.01"
                               value="{{ $probeWeight !== '' ? $probeWeight : '1' }}">
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-outline-primary" type="submit">Quote</button>
                    </div>
                </form>

                @if ($probe !== null)
                    @if ($probe['available'])
                        <div class="alert alert-success py-2 mb-0">
                            <strong>BDT {{ number_format((float) $probe['amount'], 2) }}</strong>
                            — source: <code>{{ $probe['source'] }}</code>, currency: {{ $probe['currency'] }}
                        </div>
                    @else
                        <div class="alert alert-warning py-2 mb-0">{{ $probe['reason'] }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>

    <div class="erp-card">
        <h2 class="erp-h3">Webhook event vocabulary</h2>
        <p class="erp-page-sub">Adapter-declared codes this provider may push. Anything outside this table and the canonical set is rejected with an honest error.</p>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>{{ $adapter->label() }} code</th>
                        <th>Canonical code</th>
                        <th>Canonical meaning</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($adapter->eventMap() as $providerCode => $canonical)
                        <tr>
                            <td><code>{{ $providerCode }}</code></td>
                            <td><code>{{ $canonical }}</code></td>
                            <td>{{ $codes[$canonical] ?? $canonical }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-3">No provider vocabulary — canonical codes only.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
