@php
    /* §11 — who this channel can actually reach, and who it cannot. */
    $label = $channels[$channel]['label'];
    $rows = $reach['rows'];
    $field = $channels[$channel]['contact'] === 'email' ? 'email address' : 'phone number';
    $fieldLabel = $channels[$channel]['contact'] === 'email' ? 'Address' : 'Phone';
    $verdicts = [
        'opted_out' => ['warn', 'Opted out'],
        'blacklisted' => ['danger', 'Blacklisted'],
        'no_address' => ['warn', 'No address'],
    ];
@endphp

<x-ui.page-header
    :eyebrow="'Marketing · '.$label.' · Contacts'"
    :title="$label.' can reach '.$reach['reachable'].' of '.$rows->count().' active customers today'"
    :subtitle="'The list below is the dispatcher\'s own rule, run early rather than after a campaign has gone out: a customer is reachable when they have a '.$field.' on file, are not blacklisted and have not opted out. Everybody else is still listed, with the single reason they would be skipped — nothing is filtered away silently, because a contact list that quietly drops people is how a campaign reaches fewer customers than you think it will.'"
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('marketing.campaigns.create', ['channel' => $channel]) }}">
                <i class="bi bi-megaphone" aria-hidden="true"></i> Write to them
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('marketing.deliveries', ['channel' => $channel]) }}">
            <i class="bi bi-send-check" aria-hidden="true"></i> Delivery
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.optouts', ['channel' => $channel]) }}">
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Opt-outs
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Reachable" :value="$reach['reachable']" icon="bi-check2-circle" hero
              hint="A campaign to this channel would write to these people" />
    <x-ui.kpi label="Opted out" :value="$reach['opted_out']" icon="bi-slash-circle"
              :hint="$reach['opted_out'] > 0 ? 'They asked not to be written to on this channel' : 'Nobody on this channel has opted out'" />
    <x-ui.kpi label="No address" :value="$reach['no_address']" icon="bi-telephone-x"
              :hint="'No '.$field.' on the customer record'" />
    <x-ui.kpi label="Blacklisted" :value="$reach['blacklisted']" icon="bi-ban"
              hint="A commercial decision on the customer record — nothing is marketed to them" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('marketing.contacts') }}">
    <div class="erp-filter">
        <label class="form-label" for="channel">Channel</label>
        <select class="form-select" name="channel" id="channel" data-erp-autosubmit>
            @foreach ($channels as $key => $row)
                <option value="{{ $key }}" @selected($channel === $key)>{{ $row['label'] }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Show</button>
    </div>
</form>

<x-ui.table-shell
    :title="$label.' contacts'"
    :count="$rows->count().' active customer(s)'"
    stack="true">
    <thead>
        <tr>
            <th>Customer</th>
            <th>{{ $fieldLabel }}</th>
            <th>Would a campaign reach them?</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td data-label="Customer">
                    <a class="erp-cell-strong" href="{{ route('customers.show', $row['customer']->id) }}">{{ $row['customer']->name }}</a>
                    <div class="erp-td-muted">{{ $row['customer']->code }}{{ $row['customer']->segment ? ' · '.$row['customer']->segment : '' }}</div>
                </td>
                <td data-label="{{ $fieldLabel }}">{{ $row['contact'] ?? '—' }}</td>
                <td data-label="Reachable">
                    @if ($row['verdict'] === 'reachable')
                        <x-ui.status value="ok" label="Reachable" />
                    @else
                        <x-ui.status :value="$verdicts[$row['verdict']][0]" :label="$verdicts[$row['verdict']][1]" />
                        <div class="erp-td-muted">{{ $row['reason'] }}</div>
                    @endif
                </td>
                <td class="erp-td-actions">
                    @if ($canManage && $row['verdict'] === 'opted_out')
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.optouts', ['q' => $row['contact']]) }}">The opt-out</a>
                    @elseif ($canManage && $row['verdict'] === \App\Domain\Marketing\CampaignRecipient::SKIP_NO_ADDRESS)
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('customers.show', $row['customer']->id) }}">Add it on the customer</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4">
                    <x-ui.empty
                        title="No active customers yet"
                        text="This list is built from the customer master — everybody active, with the reason they can or cannot be written to on this channel. It fills up as customers are added."
                        icon="bi-people" />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<div class="erp-note mt-3">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <div>
        <strong>This is a snapshot of the rule, not a saved list.</strong>
        A campaign expands the same rule again at the moment it is launched, so a customer who opts out between now and then is skipped — the list you are looking at is what <em>would</em> happen if you launched right now.
    </div>
</div>

<x-ui.related-pages />
