<x-ui.page-header
    eyebrow="Business Management · Notice board"
    title="What the company has told everybody"
    subtitle="A notice is addressed to an audience — everybody, the people holding certain roles, the people of certain branches, or named people — and that audience is written down when it is published, not guessed at later. A notice that asks for acknowledgement keeps a ledger of who has read it, and a row in that ledger survives a refresh, a new login and somebody emptying their inbox."
    :pin="true">
    <x-slot:actions>
        @if ($canPublish)
            <a class="btn btn-outline-secondary" href="{{ route('notices.register') }}">
                <i class="bi bi-card-list" aria-hidden="true"></i> Register
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('notices.tracking') }}">
                <i class="bi bi-clipboard2-check" aria-hidden="true"></i> Acknowledgement tracking
            </a>
            <a class="btn btn-primary" href="{{ route('notices.create') }}">
                <i class="bi bi-megaphone" aria-hidden="true"></i> New notice
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-kpi-grid">
    <x-ui.kpi label="Waiting for you" :value="$awaiting === [] ? 'None' : count($awaiting).' notice(s)'"
              icon="bi-pen" hint="Notices that ask for an acknowledgement and do not have yours yet" />
    <x-ui.kpi label="Live notices" :value="$notices->count()" icon="bi-megaphone"
              hint="Published, addressed to you, and not expired" />
    <x-ui.kpi label="Categories" :value="count($categories)" icon="bi-tags"
              hint="General, policy, urgent, people and finance" />
</div>

<form class="erp-filterbar" method="GET" action="{{ route('notices.index') }}">
    <div class="erp-filter">
        <label class="form-label" for="category">Category</label>
        <select class="form-select" name="category" id="category" data-erp-autosubmit>
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

@forelse ($notices as $notice)
    @php($toAcknowledge = in_array($notice->id, $awaiting, true))
    <article class="erp-card mt-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">
                    <a href="{{ route('notices.show', $notice) }}">{{ $notice->title }}</a>
                </h2>
                <p class="erp-card-sub">
                    <span class="erp-chip erp-chip-soft">{{ $notice->categoryLabel() }}</span>
                    published {{ optional($notice->published_at)->format('d M Y, H:i') }}
                    · by {{ $notice->creator?->name ?? 'the system' }}
                    @if ($notice->expires_at)
                        · until {{ $notice->expires_at->format('d M Y') }}
                    @endif
                </p>
            </div>
            <div class="erp-card-actions">
                @if ($notice->requires_acknowledgement)
                    @if ($toAcknowledge)
                        <span class="erp-chip erp-chip-warn"><i class="bi bi-pen" aria-hidden="true"></i> Waiting for you</span>
                    @else
                        <span class="erp-chip erp-chip-ok"><i class="bi bi-check2" aria-hidden="true"></i> Acknowledged</span>
                    @endif
                @endif
            </div>
        </div>
        <div class="p-3 pt-0">
            <p class="mb-2">{{ \Illuminate\Support\Str::limit(trim(strip_tags($notice->body)), 260) }}</p>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('notices.show', $notice) }}">
                Read it <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </article>
@empty
    <x-ui.empty
        class="mt-3"
        icon="bi-megaphone"
        title="Nothing has been posted to you"
        text="Live notices addressed to you appear here. When something is published that asks for an acknowledgement, it will stay at the top until yours is recorded." />
@endforelse
