@php
    /* §11 — the bodies the desk reuses, channel by channel. */
    $grouped = $templates->groupBy('channel');
@endphp

<x-ui.page-header
    eyebrow="Marketing · Templates"
    title="The message bodies we reuse"
    subtitle="Written once, sent many times — and rendered per recipient at launch, so {customer} is the person actually reading it. A body that has been sent is not deleted: campaigns point at it, and the report has to be able to say which wording went out. Turn one off instead when it has had its day."
    :pin="true">
    <x-slot:actions>
        @if ($canManage)
            <a class="btn btn-primary" href="{{ route('marketing.templates.create') }}">
                <i class="bi bi-plus-lg" aria-hidden="true"></i> New template
            </a>
        @endif
        <a class="btn btn-outline-secondary" href="{{ route('marketing.campaigns.index') }}">
            <i class="bi bi-megaphone" aria-hidden="true"></i> Campaigns
        </a>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.optouts') }}">
            <i class="bi bi-slash-circle" aria-hidden="true"></i> Opt-outs
        </a>
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-note erp-note-info mb-3">
    <i class="bi bi-braces" aria-hidden="true"></i>
    <div>
        <strong>These placeholders are the only ones that get replaced:</strong>
        @foreach ($placeholders as $placeholder)
            <code>{{ $placeholder }}</code>@if (! $loop->last) · @endif
        @endforeach
        <span class="d-block mt-1">Anything else stays exactly as typed — braces and all. That is deliberate: a body promising a figure the system does not know would go out promising it.</span>
    </div>
</div>

@forelse ($grouped as $channel => $rows)
    <x-ui.table-shell
        :title="($channels[$channel]['label'] ?? ucfirst($channel)).' templates'"
        :count="$rows->count().' body(ies)'"
        stack="true">
        <x-slot:tools>
            <span class="erp-td-muted">
                {{ $rows->where('is_active', true)->count() }} active · {{ $rows->where('is_active', false)->count() }} switched off
            </span>
        </x-slot:tools>

        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Body</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $template)
                <tr>
                    <td data-label="Code">
                        <span class="erp-cell-strong">{{ $template->code }}</span>
                        @if ($template->company_id === null)
                            <div class="erp-td-muted">system body — shared by every company</div>
                        @endif
                    </td>
                    <td data-label="Name">
                        {{ $template->name }}
                        @if ($template->subject)
                            <div class="erp-td-muted">“{{ $template->subject }}”</div>
                        @endif
                    </td>
                    <td data-label="Body" class="erp-td-wrap">
                        <span class="erp-td-muted">{{ \Illuminate\Support\Str::limit($template->body, 140) }}</span>
                    </td>
                    <td data-label="State">
                        <x-ui.status :value="$template->is_active ? 'active' : 'paused'" :label="$template->is_active ? 'Active' : 'Switched off'" />
                    </td>
                    <td class="erp-td-actions">
                        @if ($canManage && $template->company_id !== null)
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('marketing.templates.edit', $template) }}">Edit</a>
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('marketing.campaigns.create', ['template' => $template->id]) }}">Use it</a>
                        @elseif ($canManage)
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('marketing.campaigns.create', ['template' => $template->id]) }}">Use it</a>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table-shell>
@empty
    <x-ui.table-shell title="Templates" count="0 bodies">
        <tbody>
            <tr>
                <td>
                    <x-ui.empty
                        title="No message bodies yet"
                        text="This is where the wording lives that campaigns are built from. The desk writes one starter set the first time it is opened — if it is empty, nothing has been written for this company yet."
                        icon="bi-file-text" />
                </td>
            </tr>
        </tbody>
    </x-ui.table-shell>
@endforelse

<x-ui.related-pages />
