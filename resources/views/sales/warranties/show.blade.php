@php
    /* §16-17 — one cover: the promise, the dates read against the clock, and
       everything claimed against it. */
    $days = $warranty->daysRemaining();
    $state = $warranty->stateNow();
    $dayLabel = fn ($date): string => $date === null ? '—' : $date->format('d M Y');
    $money = fn ($value): string => '৳'.number_format((float) $value, 2);
@endphp

<x-ui.page-header
    eyebrow="Sales · Warranty {!! $warranty->code !!}"
    :title="$warranty->product?->name.' — '.$warranty->stateLabel()"
    subtitle="The dates on this page were fixed when the cover was created and have not moved since; the state beside them is read from the clock at the moment you opened this page. A claim recorded below never edits the cover — it is an event on it, kept with who decided what."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('sales.warranties.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Register
        </a>
        @if ($canManage && $state !== 'voided')
            <a class="btn btn-outline-danger" href="#withdraw" data-bs-toggle="collapse">
                <i class="bi bi-slash-circle" aria-hidden="true"></i> Withdraw cover
            </a>
        @endif
        @if ($canManage && $warranty->isLive())
            <a class="btn btn-primary" href="#raise-claim" data-bs-toggle="collapse">
                <i class="bi bi-tools" aria-hidden="true"></i> Raise a claim
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

@if ($warranty->claims->isNotEmpty() && $state === 'voided')
    <div class="erp-note erp-note-warn mb-3">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <div>
            This cover was withdrawn, but {{ $warranty->claims->count() }} claim(s) were already recorded against it.
            The register keeps both facts: what was promised, and that it was taken back.
        </div>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">The promise</h2>
                    <p class="erp-card-sub">Read from the product's policy at the moment of activation, then frozen on this row.</p>
                </div>
                <x-ui.status :value="$state" :label="$warranty->stateLabel()" lg />
            </header>
            <div class="px-3 pb-3">
                <div class="row g-3">
                    <div class="col-md-6">
                        <p class="erp-field-label">Cover runs</p>
                        <p>{{ $dayLabel($warranty->starts_on) }} → {{ $dayLabel($warranty->ends_on) }}</p>
                        <p class="erp-td-muted mb-0">
                            @if ($days < 0)
                                Ended {{ abs($days) }} day(s) ago
                            @elseif ($warranty->expiresSoon())
                                {{ $days }} day(s) left — inside the warning window
                            @else
                                {{ $days }} day(s) left
                            @endif
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="erp-field-label">Length</p>
                        <p>{{ $warranty->months }} month(s)</p>
                        <p class="erp-td-muted mb-0">One delivery line, one cover — the once-only key is on the source line.</p>
                    </div>
                    <div class="col-md-6">
                        <p class="erp-field-label">Product</p>
                        <p>{{ $warranty->product?->name ?? '—' }}</p>
                        <p class="erp-td-muted mb-0">
                            {{ $warranty->product?->code }}
                            @if ($warranty->serial_no) · serial {{ $warranty->serial_no }} @endif
                            · qty {{ rtrim(rtrim(number_format((float) $warranty->qty, 4), '0'), '.') }}
                        </p>
                    </div>
                    <div class="col-md-6">
                        <p class="erp-field-label">Customer</p>
                        <p>{{ $warranty->customer?->name ?? 'Counter / unknown' }}</p>
                        <p class="erp-td-muted mb-0">
                            @if ($warranty->invoice)
                                Invoice <a href="{{ route('sales.invoices.show', $warranty->invoice) }}">{{ $warranty->invoice->invoice_no }}</a>
                            @else
                                No invoice attached
                            @endif
                        </p>
                    </div>
                </div>

                @if ($policy !== null)
                    <div class="erp-note mt-3">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <div>
                            <strong>Policy on {{ $policy->product?->name }}: {{ $policy->months }} month(s), {{ strtolower($policy->kindLabel()) }}.</strong>
                            {{ $policy->coverLabel() }}.
                            @if ($policy->terms) {{ $policy->terms }} @endif
                            Editing this policy changes future covers only — the dates on this page stay where they are.
                        </div>
                    </div>
                @else
                    <div class="erp-note erp-note-warn mt-3">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        <div>
                            <strong>This product has no warranty policy any more.</strong>
                            The cover below was granted while one existed, or was registered by hand with its months stated;
                            the register keeps the promise either way, because a promise already made is not undone by editing a product.
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">
                        Claims against this cover
                        <span class="erp-chip erp-chip-outline">{{ $warranty->claims->count() }}</span>
                    </h2>
                    <p class="erp-card-sub">Oldest first — the history of the unit, in the order it happened.</p>
                </div>
                @if ($canManage && $warranty->isLive())
                    <div class="erp-card-actions">
                        <a class="btn btn-sm btn-primary" href="#raise-claim" data-bs-toggle="collapse">Raise a claim</a>
                    </div>
                @endif
            </header>
            <div class="px-3 pb-3">
                @forelse ($warranty->claims->sortBy('id') as $claim)
                    <div class="erp-list-row">
                        <div class="erp-list-row-main">
                            <span class="erp-cell-strong">{{ $claim->code }}</span>
                            <span class="erp-chip erp-chip-outline ms-1">{{ $claim->statusLabel() }}</span>
                            <div class="erp-td-muted">
                                Reported {{ $dayLabel($claim->reported_on) }} — {{ $claim->fault }}
                            </div>
                            @if ($claim->resolution_notes || $claim->resolution)
                                <div class="erp-td-muted">
                                    {{ $claim->resolutionLabel() }}
                                    @if ($claim->cost > 0) · cost {{ $money($claim->cost) }} @endif
                                    @if ($claim->resolved_by) · decided by {{ $claim->resolver?->name }} on {{ $dayLabel($claim->resolved_on) }} @endif
                                    @if ($claim->resolution_notes) — {{ $claim->resolution_notes }} @endif
                                </div>
                            @endif

                            @if ($canManage && $claim->isOpen())
                                <form class="erp-lookbar mt-2" method="POST" action="{{ route('sales.warranties.claims.decide', $claim) }}">
                                    @csrf
                                    <div class="erp-filter">
                                        <label class="form-label" for="status-{{ $claim->id }}">Decision</label>
                                        <select class="form-select form-select-sm" name="status" id="status-{{ $claim->id }}" required>
                                            @foreach ($claimStates as $key => $label)
                                                <option value="{{ $key }}" @selected($claim->status === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="erp-filter">
                                        <label class="form-label" for="resolution-{{ $claim->id }}">What was done</label>
                                        <select class="form-select form-select-sm" name="resolution" id="resolution-{{ $claim->id }}">
                                            <option value="">—</option>
                                            @foreach ($resolutions as $key => $label)
                                                <option value="{{ $key }}" @selected($claim->resolution === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="erp-filter">
                                        <label class="form-label" for="cost-{{ $claim->id }}">Cost</label>
                                        <input class="form-control form-control-sm" type="number" step="0.01" min="0"
                                               name="cost" id="cost-{{ $claim->id }}" value="{{ number_format((float) $claim->cost, 2, '.', '') }}">
                                    </div>
                                    <div class="erp-filter">
                                        <label class="form-label" for="notes-{{ $claim->id }}">Note</label>
                                        <input class="form-control form-control-sm" type="text" name="resolution_notes"
                                               id="notes-{{ $claim->id }}" maxlength="500" placeholder="What the technician found">
                                    </div>
                                    <button class="btn btn-sm btn-primary" type="submit">Record decision</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-ui.empty
                        icon="bi-clipboard-x"
                        title="Nothing has been claimed against this cover"
                        text="A warranty with no claims is not an empty record — it is the answer to 'has this unit ever come back?'." />
                @endforelse
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Where the cover came from</h2>
                    <p class="erp-card-sub">Activation is stamped, never back-dated by hand.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                <div class="erp-meta-row"><span class="erp-td-muted">Source</span> <strong>{{ ucfirst($warranty->source) }}</strong></div>
                <div class="erp-meta-row"><span class="erp-td-muted">Activated</span> <strong>{{ $warranty->activated_at?->format('d M Y H:i') ?? '—' }}</strong></div>
                <div class="erp-meta-row"><span class="erp-td-muted">Activated by</span> <strong>{{ $warranty->activator?->name ?? 'System' }}</strong></div>
                <div class="erp-meta-row"><span class="erp-td-muted">Branch</span> <strong>{{ $warranty->branch?->name ?? 'Company-wide' }}</strong></div>
                @if ($warranty->challan)
                    <div class="erp-meta-row">
                        <span class="erp-td-muted">Delivered on</span>
                        <strong><a href="{{ route('sales.delivery-challans.show', $warranty->challan) }}">{{ $warranty->challan->challan_no }}</a></strong>
                    </div>
                @endif
                @if ($warranty->voided_at)
                    <div class="erp-meta-row"><span class="erp-td-muted">Withdrawn</span> <strong>{{ $warranty->voided_at->format('d M Y H:i') }}</strong></div>
                    <div class="erp-meta-row"><span class="erp-td-muted">Reason</span> <strong>{{ $warranty->void_reason }}</strong></div>
                @endif

                @if ($warranty->notes)
                    <p class="erp-td-muted mt-3 mb-0">{{ $warranty->notes }}</p>
                @endif
            </div>
        </section>

        @if ($canManage && $warranty->isLive())
            <section class="collapse" id="raise-claim">
                <div class="erp-card mb-3">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Raise a claim</h2>
                            <p class="erp-card-sub">Refused for a cover that has ended or was withdrawn — a goodwill repair is an expense, not a claim.</p>
                        </div>
                    </header>
                    <div class="px-3 pb-3">
                        <form method="POST" action="{{ route('sales.warranties.claims.store', $warranty) }}">
                            @csrf
                            <div class="erp-filter mb-2">
                                <label class="form-label" for="reported_on">Reported on</label>
                                <input class="form-control" type="date" name="reported_on" id="reported_on"
                                       value="{{ old('reported_on', now()->toDateString()) }}" max="{{ now()->toDateString() }}">
                            </div>
                            <div class="erp-filter mb-2">
                                <label class="form-label" for="fault">What was reported</label>
                                <textarea class="form-control" name="fault" id="fault" rows="3" maxlength="500" required
                                          placeholder="What the customer says is wrong, in their words">{{ old('fault') }}</textarea>
                            </div>
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-tools" aria-hidden="true"></i> Record the claim
                            </button>
                        </form>
                    </div>
                </div>
            </section>
        @endif

        @if ($canManage && $state !== 'voided')
            <section class="collapse" id="withdraw">
                <div class="erp-card mb-3">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Withdraw this cover</h2>
                            <p class="erp-card-sub">For a mistake — goods returned for credit, a serial recorded against the wrong unit. The row stays, with the reason.</p>
                        </div>
                    </header>
                    <div class="px-3 pb-3">
                        <form method="POST" action="{{ route('sales.warranties.void', $warranty) }}" data-confirm="Withdraw the cover on {{ $warranty->code }}? Past claims stay on record.">
                            @csrf
                            <div class="erp-filter mb-2">
                                <label class="form-label" for="void_reason">Why</label>
                                <textarea class="form-control" name="void_reason" id="void_reason" rows="2" maxlength="500" required></textarea>
                            </div>
                            <button class="btn btn-outline-danger" type="submit">Withdraw cover</button>
                        </form>
                    </div>
                </div>
            </section>
        @endif

        <x-ui.related-pages />
    </div>
</div>
