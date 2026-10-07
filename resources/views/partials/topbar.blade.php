@php
    $context = app(\App\Domain\Foundation\Services\TenantContext::class);
    $currentBranch = $context->branch();
    $currentWarehouse = $context->warehouse();
    $translator = app(\App\Domain\Foundation\Services\Translator::class);
    $locale = $translator->locale();
    $unread = app(\App\Domain\Notification\Services\NotificationCenter::class)->unreadCount(auth()->user());

    $switchableBranches = \App\Domain\Foundation\Branch::query()
        ->where('is_active', true)
        ->orderBy('name')
        ->get()
        ->filter(fn ($b) => auth()->user()->hasBranchAccess($b->id))
        ->values();

    $switchableWarehouses = $currentBranch
        ? \App\Domain\Foundation\Warehouse::query()
            ->where('branch_id', $currentBranch->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
        : collect();
@endphp
<header class="erp-topbar">
    <button class="erp-icon-btn d-lg-none" type="button" data-erp-sidebar-open aria-label="Open navigation">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <div class="erp-topbar-title d-none d-md-block">
        <span class="erp-topbar-crumb">@yield('page_title', 'Dashboard')</span>
        @if($currentBranch)
            <span class="erp-chip erp-chip-soft" title="Current branch context">
                <i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $currentBranch->name }}
            </span>
        @endif
        @if($currentWarehouse)
            <span class="erp-chip erp-chip-soft" title="Current warehouse context">
                <i class="bi bi-house" aria-hidden="true"></i>{{ $currentWarehouse->name }}
            </span>
        @endif
    </div>

    <form class="erp-topbar-search d-none d-md-flex" method="GET" action="{{ route('search.index') }}" role="search">
        <label class="visually-hidden" for="topbarSearch">Search</label>
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="erp-topbar-search-input" type="search" id="topbarSearch" name="q"
               value="{{ request()->routeIs('search.index') ? request('q') : '' }}"
               placeholder="Search…" autocomplete="off">
    </form>

    <div class="erp-topbar-actions">
        @if($switchableBranches->count() > 1)
            <form class="erp-switcher" method="POST" action="{{ route('context.branch') }}">
                @csrf
                <label class="visually-hidden" for="branchSwitch">Branch</label>
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                <select class="erp-switcher-select" id="branchSwitch" name="branch_id" data-erp-autosubmit>
                    @foreach($switchableBranches as $b)
                        <option value="{{ $b->id }}" @selected($currentBranch && $b->id === $currentBranch->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        @if($switchableWarehouses->count() > 1)
            <form class="erp-switcher d-none d-md-flex" method="POST" action="{{ route('context.warehouse') }}">
                @csrf
                <label class="visually-hidden" for="warehouseSwitch">Warehouse</label>
                <i class="bi bi-house" aria-hidden="true"></i>
                <select class="erp-switcher-select" id="warehouseSwitch" name="warehouse_id" data-erp-autosubmit>
                    @foreach($switchableWarehouses as $w)
                        <option value="{{ $w->id }}" @selected($currentWarehouse && $w->id === $currentWarehouse->id)>{{ $w->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        <form method="POST" action="{{ route('context.locale') }}" class="d-inline">
            @csrf
            <input type="hidden" name="locale" value="{{ $locale === 'en' ? 'bn' : 'en' }}">
            <button class="erp-lang-btn" type="submit" title="Switch language">
                {{ $locale === 'en' ? 'বাংলা' : 'EN' }}
            </button>
        </form>

        <div class="dropdown">
            <button class="erp-icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="erp-badge-count" data-notif-count>{{ $unread > 0 ? $unread : '' }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end erp-notif-menu" data-erp-notif-menu>
                <div class="dropdown-header d-flex justify-content-between align-items-center">
                    <span>Notifications</span>
                    <span class="erp-chip erp-chip-soft" data-notif-unread>{{ $unread }} unread</span>
                </div>
                <div data-erp-notif-list>
                    <p class="dropdown-item-text erp-notif-loading">Loading latest…</p>
                </div>
                <a class="dropdown-item text-center fw-semibold" href="{{ route('notifications.index') }}">View all notifications</a>
            </div>
        </div>

        <div class="dropdown">
            <button class="erp-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                <div class="dropdown-header">
                    <strong>{{ auth()->user()->name }}</strong>
                    <small class="d-block text-body-secondary">{{ auth()->user()->email }}</small>
                </div>
                @foreach($headerEntries as $entry)
                    @continue(str_contains($entry['route'], '/notifications'))
                    <a class="dropdown-item" href="{{ strtok($entry['route'], '#') }}">
                        @if($entry['icon'] ?? null)<i class="bi {{ $entry['icon'] }} me-2" aria-hidden="true"></i>@endif
                        {{ $tr($entry['label_key'], $entry['label']) }}
                    </a>
                @endforeach
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="dropdown-item" type="submit">
                        <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
