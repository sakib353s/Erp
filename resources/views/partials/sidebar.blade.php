{{--
    NavigationTree (§18.2) — curated, job-to-be-done navigation.

    Renders ONLY what NavigationBuilder returns: sections → module groups →
    canonical destinations, permission-filtered upstream. Unauthorized items are
    absent, never disabled (§18.3). Depth is capped by config, so the rail can
    never grow back into the supplied 1,022-line spec tree.
--}}
<aside class="erp-sidebar" id="erpSidebar" aria-label="Primary navigation">
    <div class="erp-sidebar-head">
        <a class="erp-brand" href="{{ route('dashboard') }}">
            <span class="erp-brand-mark" aria-hidden="true">{{ strtoupper(mb_substr(config('app.name'), 0, 1)) }}E</span>
            <span class="erp-brand-text">
                <strong>{{ config('app.name') }}</strong>
                <small>{{ $company?->name ?? 'Workspace' }}</small>
            </span>
        </a>
        <button class="erp-icon-btn d-lg-none" type="button" data-erp-sidebar-close aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <div class="erp-sidebar-context">
        <button class="erp-nav-search" type="button" data-palette-open>
            <i class="bi bi-search" aria-hidden="true"></i>
            <span>Search or jump to…</span>
            <kbd>⌘K</kbd>
        </button>
    </div>

    <nav class="erp-sidebar-nav" aria-label="Sections">
        @forelse($sidebarSections as $section)
            <div class="erp-nav-section" data-hue="{{ $section['hue'] ?? 0 }}">
                <p class="erp-nav-section-label">
                    @if($section['icon'] ?? null)
                        <i class="bi {{ $section['icon'] }}" aria-hidden="true"></i>
                    @endif
                    <span>{{ $tr('nav.section.'.$section['code'], $section['label']) }}</span>
                    @if(! empty($section['hint']))
                        <span class="erp-nav-section-count">{{ $section['hint'] }}</span>
                    @endif
                </p>

                @include('partials.menu-nodes', ['nodes' => $section['items'], 'level' => 1])
            </div>
        @empty
            <p class="erp-nav-empty">
                No navigation entries are available for your access.
                Ask an administrator to grant your role a module.
            </p>
        @endforelse
    </nav>

    <div class="erp-sidebar-foot">
        @foreach($utilityEntries as $entry)
            <a class="erp-util-link {{ \Illuminate\Support\Str::startsWith(request()->path(), trim(explode('?', $entry['route'])[0], '/')) ? 'active' : '' }}"
               href="{{ explode('#', $entry['route'])[0] }}">
                <i class="bi {{ $entry['icon'] ?: 'bi-grid' }}" aria-hidden="true"></i>
                <span>{{ $tr($entry['label_key'], $entry['label']) }}</span>
            </a>
        @endforeach

        <div class="erp-sidebar-foot-row">
            <button class="erp-rail-toggle d-none d-lg-flex" type="button" data-erp-rail-toggle aria-expanded="true"
                    title="Collapse navigation">
                <i class="bi bi-chevron-double-left" aria-hidden="true"></i>
                <span>Collapse</span>
            </button>
        </div>

        <span class="erp-version">Build {{ now()->year }}.10 · v2</span>
    </div>
</aside>
