<x-ui.page-header
    eyebrow="Business Management · Notice board"
    title="Every notice, including the drafts"
    subtitle="The board shows what is live for one person; this is the register — what was written, when it went out, who it was addressed to and how many of them have acknowledged it. A notice is never deleted: archiving takes it off the board and leaves it here and in the audit trail.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('notices.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Board
        </a>
        @if ($perm('business.notices.create'))
            <a class="btn btn-primary" href="{{ route('notices.create') }}">
                <i class="bi bi-megaphone" aria-hidden="true"></i> New notice
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<form class="erp-filterbar" method="GET" action="{{ route('notices.register') }}">
    <div class="erp-filter-wide">
        <label class="form-label" for="q">Search</label>
        <input class="form-control" type="search" name="q" id="q" value="{{ $search }}" placeholder="Title or text">
    </div>
    <div class="erp-filter">
        <label class="form-label" for="status">State</label>
        <select class="form-select" name="status" id="status">
            <option value="">Every state</option>
            @foreach ($statuses as $key => $label)
                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filter">
        <label class="form-label" for="category">Category</label>
        <select class="form-select" name="category" id="category">
            <option value="">Everything</option>
            @foreach ($categories as $key => $label)
                <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="erp-filterbar-actions">
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
    </div>
</form>

<x-ui.table-shell title="Notices" :count="$notices->total().' notice(s)'">
    <thead>
        <tr>
            <th>Notice</th>
            <th>Audience</th>
            <th>Published</th>
            <th class="erp-th-num">Acknowledged</th>
            <th>State</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        @forelse ($notices as $notice)
            <tr>
                <td>
                    <a class="erp-cell-strong" href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a>
                    <div class="erp-td-muted">
                        {{ $notice->categoryLabel() }}
                        · {{ $notice->creator?->name ?? 'the system' }}
                        @if ($notice->requires_acknowledgement) · acknowledgement required @endif
                    </div>
                </td>
                <td class="erp-td-muted">
                    {{ ucfirst($notice->audience_type) }}
                    @if (! empty($notice->audience)) ({{ count($notice->audience) }}) @endif
                </td>
                <td class="erp-td-muted">{{ optional($notice->published_at)->format('d M Y') ?? '—' }}</td>
                <td class="erp-td-num">
                    @if ($notice->requires_acknowledgement)
                        {{ $notice->acknowledgements()->count() }}
                    @else
                        <span class="erp-td-muted">not asked</span>
                    @endif
                </td>
                <td><x-ui.status :value="$notice->status" :label="\App\Domain\Business\Notice::STATUSES[$notice->status] ?? $notice->status" /></td>
                <td class="text-end">
                    <div class="d-flex gap-1 justify-content-end">
                        @if ($notice->status === 'draft' && $perm('business.notices.create'))
                            <form method="POST" action="{{ route('notices.publish', $notice) }}" data-confirm="Publish “{{ $notice->title }}” to its audience?">
                                @csrf
                                <button class="btn btn-outline-secondary btn-sm" type="submit">Publish</button>
                            </form>
                        @endif
                        @if ($notice->status === 'published' && $perm('business.notices.create'))
                            <form method="POST" action="{{ route('notices.archive', $notice) }}" data-confirm="Archive “{{ $notice->title }}”? It stays on the register.">
                                @csrf
                                <button class="btn btn-outline-secondary btn-sm" type="submit">Archive</button>
                            </form>
                        @endif
                        <a class="btn btn-outline-secondary btn-sm" href="{{ route('notices.show', $notice) }}">Open</a>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="6">
                    <x-ui.empty icon="bi-card-list" title="No notices match"
                                text="Clear the filters, or write the first notice — a draft is only visible here until it is published." />
                </td>
            </tr>
        @endforelse
    </tbody>
</x-ui.table-shell>

<div class="mt-3">{{ $notices->links() }}</div>
