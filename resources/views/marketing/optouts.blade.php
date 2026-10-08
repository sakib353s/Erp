@php
    /* §11 — the opt-out register: who asked us not to write, and on what. */
    $channelLabels = $channels;
@endphp

<x-ui.page-header
    eyebrow="Marketing · Opt-outs"
    title="People who asked us not to write to them"
    subtitle="Kept as a promise, not a preference. The entry is keyed on the contact itself — the number or the address — because the person who says stop owns it, whether or not it was typed into the customer master correctly. An entry covering every channel means exactly that. The dispatcher reads this before anything is queued, so an opt-out is honoured by the machine rather than remembered by a person."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.campaigns.index') }}">
            <i class="bi bi-megaphone" aria-hidden="true"></i> Campaigns
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.reports') }}">
            <i class="bi bi-graph-up" aria-hidden="true"></i> Cost vs revenue
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Opted out" :value="$overview['optouts']" icon="bi-slash-circle" hero
              hint="Contacts on this register, across every channel" />
    <x-ui.kpi label="Skipped at launch" :value="$overview['skipped_opted_out']" icon="bi-x-octagon"
              hint="Recipients this rule took out of campaigns that ran" />
    <x-ui.kpi label="Recipients written to" :value="$overview['recipients']" icon="bi-people"
              :hint="$overview['held'] > 0 ? $overview['held'].' of them are held for want of a transport' : 'Every one of them was handed over or skipped for a stated reason'" />
</div>

@if ($canManage)
    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Record an opt-out</h2>
                <p class="erp-card-sub">Somebody said stop — write it down here, so nobody has to remember it. The contact does not have to match a customer on file.</p>
            </div>
        </div>

        @error('contact')
            <div class="alert alert-danger py-2">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('marketing.optouts.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label" for="channel">Channel</label>
                    <select class="form-select" name="channel" id="channel" required>
                        @foreach ($channelLabels as $key => $label)
                            <option value="{{ $key }}" @selected(old('channel') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">“Every channel” is what somebody writing “stop everything” has asked for.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="contact">Phone or address that asked</label>
                    <input class="form-control" type="text" name="contact" id="contact" value="{{ old('contact') }}"
                           maxlength="191" required placeholder="01711 000000">
                </div>

                <div class="col-md-5">
                    <label class="form-label" for="reason">What they said <span class="erp-td-muted">(optional)</span></label>
                    <input class="form-control" type="text" name="reason" id="reason" value="{{ old('reason') }}"
                           maxlength="500" placeholder="Replied STOP to the Eid offer">
                </div>

                <input type="hidden" name="source" value="manual">
            </div>

            <div class="erp-form-actions">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-slash-circle" aria-hidden="true"></i> Record it
                </button>
                <span class="erp-td-muted">Recording the same contact twice is not an error — it is one entry, not two.</span>
            </div>
        </form>
    </div>
@endif

<form class="erp-filterbar" method="GET" action="{{ route('marketing.optouts') }}">
    <div class="erp-filter">
        <label class="form-label" for="filter_channel">Channel</label>
        <select class="form-select" name="channel" id="filter_channel" data-erp-autosubmit>
            <option value="">Every channel</option>
            @foreach ($channelLabels as $key => $label)
                <option value="{{ $key }}" @selected($channel === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="q">Contact</label>
        <input class="form-control erp-filter-wide" type="search" name="q" id="q" value="{{ $search }}" placeholder="phone or address">
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        @if ($search !== '' || $channel !== null)
            <a class="btn btn-link" href="{{ route('marketing.optouts') }}">Reset</a>
        @endif
    </div>
</form>

<x-ui.table-shell title="The register" :count="$optouts->count().' entry(ies)'" stack="true">
    <thead>
        <tr>
            <th>Contact</th>
            <th>Channel</th>
            <th>Customer</th>
            <th>How it was recorded</th>
            <th>When</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($optouts as $optout)
            <tr>
                <td data-label="Contact"><span class="erp-cell-strong">{{ $optout->contact }}</span></td>
                <td data-label="Channel">
                    <span class="erp-chip erp-chip-outline">{{ $optout->channelLabel() }}</span>
                    @if ($optout->channel === \App\Domain\Marketing\MarketingOptout::CHANNEL_ALL)
                        <div class="erp-td-muted">every channel we have</div>
                    @endif
                </td>
                <td data-label="Customer">
                    @if ($optout->customer)
                        <a href="{{ route('customers.show', $optout->customer_id) }}">{{ $optout->customer->name }}</a>
                    @else
                        <span class="erp-td-muted">not matched to a customer</span>
                    @endif
                </td>
                <td data-label="How it was recorded">
                    {{ $optout->sourceLabel() }}
                    @if ($optout->reason)
                        <div class="erp-td-muted">{{ $optout->reason }}</div>
                    @endif
                    @if ($optout->campaign)
                        <div class="erp-td-muted">during {{ $optout->campaign->code }}</div>
                    @endif
                </td>
                <td data-label="When">
                    {{ $optout->created_at?->format('d M Y') }}
                    <div class="erp-td-muted">{{ $optout->creator?->name ?? 'the system' }}</div>
                </td>
                <td class="erp-td-actions">
                    @if ($canManage)
                        <form method="POST" action="{{ route('marketing.optouts.destroy', $optout) }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm btn-outline-secondary" type="submit"
                                    data-confirm="Lift the opt-out for {{ $optout->contact }} on {{ strtolower($optout->channelLabel()) }}? They may be written to again from now on.">
                                Lift it
                            </button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty
                        title="Nobody has opted out"
                        text="Every contact is currently fair game on every channel — which is exactly the state to be in, and exactly the state to keep a record of when it changes."
                        icon="bi-slash-circle" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<div class="erp-note erp-note-info mt-3">
    <i class="bi bi-shield-check" aria-hidden="true"></i>
    <div>
        <strong>Opting out is not the same as being blacklisted.</strong>
        An opt-out is a marketing promise about one contact on one channel: the customer still gets their invoices and delivery messages.
        A blacklisted customer is a commercial decision that stops the selling, and it lives on the customer record.
    </div>
</div>

<x-ui.related-pages />
