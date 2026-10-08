@php
    /* §12-14 — register an asset. Step one: what kind of thing is it. Step two:
       what it is. The kind comes first because it decides what else to ask and
       what the depreciation default should be. */
    $config = $category !== null ? $registry->config($category) : null;
    $isVehicle = $category === \App\Domain\Business\AssetRegistry::CATEGORY_VEHICLE;
    $old = fn (string $key, $fallback = null) => old($key, $fallback);
@endphp

<x-ui.page-header
    :eyebrow="$category === null ? 'Business Management · Assets · New' : 'Business Management · Assets · '.$config['label']"
    :title="$category === null ? 'What kind of thing is it?' : 'Register a '.strtolower($config['label'])"
    :subtitle="$category === null
        ? 'The kind decides what the register asks for next — a van needs plates, a driver and an odometer; a laptop needs a custodian. It also decides the default life the depreciation schedule starts from, which is a property of the thing rather than of the company.'
        : $config['hint']">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('assets.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to the register
        </a>
        @if ($category !== null)
            <a class="btn btn-outline-secondary" href="{{ route('assets.create') }}">Change the kind</a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

@if ($category === null)
    <div class="erp-card">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Pick the kind</h2>
                <p class="erp-card-sub">Six kinds, because six kinds of thing wear out differently and are asked about differently. Everything on this list lands in the same register — the kind only decides the questions.</p>
            </div>
            <span class="erp-chip erp-chip-outline">Next code: {{ $nextCode }}</span>
        </header>
        <div class="px-3 pb-3">
            @foreach ($registry->all() as $key => $kind)
                <div class="erp-list-row">
                    <div class="erp-list-row-main">
                        <span class="erp-cell-strong">
                            <i class="bi {{ $kind['icon'] }} me-1" aria-hidden="true"></i>{{ $kind['plural'] }}
                        </span>
                        <div class="erp-td-muted">{{ $kind['hint'] }}</div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <span class="erp-chip erp-chip-outline">
                            @if ($kind['wears_out'])
                                Depreciates · {{ $kind['life'] }} months by default
                            @else
                                Not normally depreciated
                            @endif
                        </span>
                        <span class="erp-chip erp-chip-soft">Ledger {{ $kind['gl'] }}</span>
                        <a class="btn btn-sm btn-primary" href="{{ route('assets.create', ['category' => $key]) }}">Choose</a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@else
    <form class="erp-form" method="POST" action="{{ route('assets.store') }}">
        @csrf
        <input type="hidden" name="category" value="{{ $category }}">

        <section class="erp-card erp-form-section">
            <header class="erp-form-section-head">
                <h2 class="erp-card-title">What it is</h2>
                <p class="erp-card-sub">The register will know it by its code and its name — a name somebody at the gate would recognise.</p>
            </header>
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="erp-field-label" for="code_display">Code</label>
                    <input class="form-control" id="code_display" value="{{ $nextCode }}" disabled>
                    <p class="erp-help">Allocated by the register when you save.</p>
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="name">Name <span aria-hidden="true">*</span></label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                           value="{{ $old('name') }}" required maxlength="191" placeholder="e.g. {{ $isVehicle ? 'Hiace delivery van 3' : $config['label'].' — ground floor' }}">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field erp-form-field-wide">
                    <label class="erp-field-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" value="{{ $old('description') }}" maxlength="500"
                           placeholder="Model, size, colour — what tells two of the same thing apart">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="condition">Condition</label>
                    <select class="form-select" name="condition" id="condition">
                        <option value="">Not assessed</option>
                        @foreach (\App\Domain\Business\AssetRegistry::CONDITIONS as $key => $label)
                            <option value="{{ $key }}" @selected($old('condition') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="acquired_on">Acquired on</label>
                    <input class="form-control" type="date" id="acquired_on" name="acquired_on" value="{{ $old('acquired_on') }}">
                </div>
            </div>
        </section>

        <section class="erp-card erp-form-section">
            <header class="erp-form-section-head">
                <h2 class="erp-card-title">Where it is, and who answers for it</h2>
                <p class="erp-card-sub">A register that says “somewhere in the warehouse” is a register that cannot find anything. Naming a custodian is what turns a list of things into a list of responsibilities.</p>
            </header>
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="erp-field-label" for="location">Location</label>
                    <input class="form-control" id="location" name="location" value="{{ $old('location') }}" maxlength="160"
                           placeholder="e.g. Head office — 2nd floor, or Vehicle pool">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="branch_id">Branch</label>
                    <select class="form-select" name="branch_id" id="branch_id">
                        <option value="">Company-wide</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}" @selected((string) $old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="custodian_id">Custodian</label>
                    <select class="form-select" name="custodian_id" id="custodian_id">
                        <option value="">Nobody named yet</option>
                        @foreach ($people as $person)
                            <option value="{{ $person->id }}" @selected((string) $old('custodian_id') === (string) $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                    <p class="erp-help">Who has it, or who is answerable for it.</p>
                </div>
            </div>
        </section>

        @if ($isVehicle)
            <section class="erp-card erp-form-section">
                <header class="erp-form-section-head">
                    <h2 class="erp-card-title">Vehicle detail</h2>
                    <p class="erp-card-sub">The plates are how the register, the papers and the person at the gate all refer to the same truck — so they are required. Fitness, insurance and the tax token are not asked for here: they are certificates with dates, so they live in the certificate register and appear on the compliance calendar with the company's trade licence.</p>
                </header>
                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="erp-field-label" for="registration_no">Registration number <span aria-hidden="true">*</span></label>
                        <input class="form-control @error('registration_no') is-invalid @enderror" id="registration_no" name="registration_no"
                               value="{{ $old('registration_no') }}" maxlength="40" placeholder="e.g. Dhaka Metro-Ga 11-2345">
                        @error('registration_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="erp-field-label" for="driver_id">Regular driver</label>
                        <select class="form-select" name="driver_id" id="driver_id">
                            <option value="">Nobody assigned</option>
                            @foreach ($people as $person)
                                <option value="{{ $person->id }}" @selected((string) $old('driver_id') === (string) $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="erp-form-field">
                        <label class="erp-field-label" for="driver_name">Or a driver without an account</label>
                        <input class="form-control" id="driver_name" name="driver_name" value="{{ $old('driver_name') }}" maxlength="120"
                               placeholder="e.g. Md. Rafiq (contract)">
                    </div>
                    <div class="erp-form-field">
                        <label class="erp-field-label" for="odometer_reading">Odometer now (km)</label>
                        <input class="form-control" type="number" min="0" id="odometer_reading" name="odometer_reading" value="{{ $old('odometer_reading') }}">
                        <p class="erp-help">The reading today. Each trip moves it on as trips are logged.</p>
                    </div>
                    <div class="erp-form-field">
                        <label class="erp-field-label" for="engine_no">Engine number</label>
                        <input class="form-control" id="engine_no" name="engine_no" value="{{ $old('engine_no') }}" maxlength="60">
                    </div>
                    <div class="erp-form-field">
                        <label class="erp-field-label" for="chassis_no">Chassis number</label>
                        <input class="form-control" id="chassis_no" name="chassis_no" value="{{ $old('chassis_no') }}" maxlength="60">
                    </div>
                </div>
            </section>
        @endif

        <section class="erp-card erp-form-section">
            <header class="erp-form-section-head">
                <h2 class="erp-card-title">What it cost</h2>
                <p class="erp-card-sub">The register does not post the purchase — the books do — but it needs the cost to know what depreciation is charged against, and the invoice reference to point at when somebody asks.</p>
            </header>
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="erp-field-label" for="acquisition_cost">Acquisition cost (৳)</label>
                    <input class="form-control" type="number" step="0.01" min="0" id="acquisition_cost" name="acquisition_cost" value="{{ $old('acquisition_cost') }}">
                    @error('acquisition_cost')<div class="erp-field-error">{{ $message }}</div>@enderror
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="supplier_name">Bought from</label>
                    <input class="form-control" id="supplier_name" name="supplier_name" value="{{ $old('supplier_name') }}" maxlength="160">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="invoice_ref">Invoice reference</label>
                    <input class="form-control" id="invoice_ref" name="invoice_ref" value="{{ $old('invoice_ref') }}" maxlength="120">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="warranty_expires_on">Warranty runs to</label>
                    <input class="form-control" type="date" id="warranty_expires_on" name="warranty_expires_on" value="{{ $old('warranty_expires_on') }}">
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="gl_account_code">Ledger account</label>
                    <input class="form-control" id="gl_account_code" name="gl_account_code" value="{{ $old('gl_account_code', $config['gl']) }}" maxlength="12">
                    <p class="erp-help">Where the cost sits in the chart of accounts. Defaults to {{ $config['gl'] }} for {{ strtolower($config['plural']) }}.</p>
                </div>
            </div>
        </section>

        <section class="erp-card erp-form-section">
            <header class="erp-form-section-head">
                <h2 class="erp-card-title">How it wears out</h2>
                <p class="erp-card-sub">Straight line means the same charge every month until the life runs out. Choosing “not depreciated” is allowed — what is not allowed is a cost with no decision behind it, because that is the asset nobody notices is still on the balance sheet at full value ten years later.</p>
            </header>
            <div class="erp-form-grid">
                <div class="erp-form-field">
                    <label class="erp-field-label" for="depreciation_method">Method</label>
                    <select class="form-select" name="depreciation_method" id="depreciation_method">
                        @foreach (\App\Domain\Business\AssetRegistry::METHODS as $key => $label)
                            <option value="{{ $key }}" @selected($old('depreciation_method', $config['wears_out'] ? 'straight_line' : 'none') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="useful_life_months">Useful life (months)</label>
                    <input class="form-control" type="number" min="1" max="600" id="useful_life_months" name="useful_life_months"
                           value="{{ $old('useful_life_months', $config['life']) }}">
                    <p class="erp-help">{{ strtolower($config['plural']) }} default to {{ $config['life'] }} months ({{ round($config['life'] / 12, 1) }} years).</p>
                </div>
                <div class="erp-form-field">
                    <label class="erp-field-label" for="salvage_value">Salvage value (৳)</label>
                    <input class="form-control" type="number" step="0.01" min="0" id="salvage_value" name="salvage_value" value="{{ $old('salvage_value', '0') }}">
                    <p class="erp-help">What it will still be worth at the end. The charge is spread over cost less this.</p>
                </div>
                <div class="erp-form-field erp-form-field-wide">
                    <label class="erp-field-label" for="notes">Notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000">{{ $old('notes') }}</textarea>
                </div>
            </div>
            <div class="erp-note erp-note-info">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <div>Nothing is depreciated until the asset is <strong>capitalised</strong> — the moment somebody confirms its cost is in the books. Register it now, capitalise it when the purchase is posted, and the depreciation desk will charge one month at a time from there, with a journal entry behind every charge.</div>
            </div>
        </section>

        <div class="erp-form-actions">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check2" aria-hidden="true"></i> Put it on the register
            </button>
            <a class="btn btn-link" href="{{ route('assets.index') }}">Cancel</a>
        </div>
    </form>
@endif

<x-ui.related-pages />
