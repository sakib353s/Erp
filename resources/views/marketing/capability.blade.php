@php
    /* §11 — a menu leaf this application will not fake. */
    $instead = $capability['instead'] ?? null;
@endphp

<x-ui.page-header
    eyebrow="Marketing · {{ $capability['eyebrow'] }}"
    :title="$capability['title']"
    subtitle="The catalogue has a leaf for this. Rather than open an empty chart, this page says exactly what is not built, why it cannot be built honestly in this deployment, and which real screen does the part of the job that can be done — so nobody has to guess whether a number is missing or zero."
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

<div class="erp-note erp-note-warn mb-3">
    <i class="bi bi-slash-circle" aria-hidden="true"></i>
    <div>
        <strong>{{ $capability['eyebrow'] }}.</strong>
        {{ $capability['summary'] }}
    </div>
</div>

<div class="erp-card mb-3">
    <div class="erp-card-head">
        <div>
            <h2 class="erp-card-title">Why it is not here</h2>
            <p class="erp-card-sub">Each of these is a fact about this deployment, not a to-do somebody forgot.</p>
        </div>
    </div>

    <ul class="erp-list">
        @foreach ($capability['details'] as $detail)
            <li>{{ $detail }}</li>
        @endforeach
    </ul>
</div>

@if ($instead !== null)
    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What to use instead</h2>
                <p class="erp-card-sub">The honest half of this job is already built — it just is not called by this leaf's name.</p>
            </div>
        </div>

        <a class="btn btn-primary" href="{{ route($instead[0], $instead[2] ?? []) }}">
            <i class="bi bi-arrow-right" aria-hidden="true"></i> {{ $instead[1] }}
        </a>
    </div>
@endif

@if ($canManage)
    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">If this is needed for real</h2>
                <p class="erp-card-sub">What it would take — written down so the next person does not have to work it out again.</p>
            </div>
        </div>

        <p class="mb-2">
            Every capability on this page needs something this deployment does not hold: a provider credential (WhatsApp business account, push service, SMS gateway balance endpoint), a public site to attach tracking to, or a webhook to receive events on. Credentials collected by a screen that cannot use them are worse than no screen at all, so none are collected here.
        </p>

        <p class="mb-0 erp-td-muted">
            The place to start is the settings screen for the channel — that is where a provider is declared and marked configured, and the messaging slices already read it from there.
        </p>
    </div>
@endif

<x-ui.table-shell title="Other leaves in the same position" :count="count($others).' shown'" stack="true">
    <thead>
        <tr>
            <th>Leaf</th>
            <th>Why</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @foreach ($others as $key => $other)
            <tr>
                <td data-label="Leaf"><span class="erp-cell-strong">{{ $other['title'] }}</span></td>
                <td data-label="Why" class="erp-td-wrap"><span class="erp-td-muted">{{ $other['eyebrow'] }}</span></td>
                <td class="erp-td-actions">
                    @if ($key !== $topic)
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.capability', $key) }}">Read it</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</x-ui.table-shell>

<x-ui.related-pages />
