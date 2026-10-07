<aside class="erp-sidebar" id="erpSidebar" aria-label="Primary navigation">
    <div class="erp-sidebar-head">
        <a class="erp-brand" href="{{ route('dashboard') }}">
            <span class="erp-brand-mark" aria-hidden="true">◆</span>
            <span class="erp-brand-text">
                <strong>{{ config('app.name') }}</strong>
                <small>{{ $company?->name }}</small>
            </span>
        </a>
        <button class="erp-icon-btn d-lg-none" type="button" data-erp-sidebar-close aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="erp-sidebar-nav">
        @include('partials.menu-nodes', ['nodes' => $sidebarTree])
    </nav>

    <div class="erp-sidebar-foot">
        @foreach($utilityEntries as $entry)
            <a class="erp-util-link {{ str_starts_with(request()->path(), trim(strtok($entry['route'], '#'), '/')) ? 'active' : '' }}"
               href="{{ strtok($entry['route'], '#') }}">
                <i class="bi {{ $entry['icon'] ?: 'bi-grid' }}" aria-hidden="true"></i>
                <span>{{ $tr($entry['label_key'], $entry['label']) }}</span>
            </a>
        @endforeach
        <span class="erp-version">Foundation build · {{ now()->year }}</span>
    </div>
</aside>
