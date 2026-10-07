{{--
    TopBar + BranchSwitcher + Breadcrumbs (§18.2).

    Order of intent, left → right: where am I (trail), what can I do here
    (context actions), where else can I go (⌘K), what changed (notifications),
    who am I (account). Nothing here duplicates the sidebar.
--}}
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

    <div class="erp-topbar-crumbs">
        @if(! empty($trail))
            <nav aria-label="Breadcrumb">
                <ol class="erp-breadcrumb">
                    @foreach($trail as $crumb)
                        <li>
                            @if(! empty($crumb['url']))
                                <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                                <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            @else
                                <span class="is-current" aria-current="page">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </nav>
        @endif

        <div class="erp-topbar-title">
            <strong class="d-none d-md-inline">@yield('page_title', 'Dashboard')</strong>

            @if($currentBranch)
                <span class="erp-chip erp-chip-outline d-none d-xl-inline-flex" title="Current branch context">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $currentBranch->name }}
                </span>
            @endif
            @if($currentWarehouse)
                <span class="erp-chip erp-chip-outline d-none d-xxl-inline-flex" title="Current warehouse context">
                    <i class="bi bi-box-seam" aria-hidden="true"></i>{{ $currentWarehouse->name }}
                </span>
            @endif
        </div>
    </div>

    <button class="erp-global-search d-none d-md-flex" type="button" data-palette-open aria-label="Search the workspace">
        <i class="bi bi-search" aria-hidden="true"></i>
        <span>Search pages, orders, invoices…</span>
        <kbd>⌘K</kbd>
    </button>

    <div class="erp-topbar-actions">
        @if($switchableBranches->count() > 1)
            <form class="erp-switcher d-none d-lg-flex" method="POST" action="{{ route('context.branch') }}">
                @csrf
                <label class="erp-visually-hidden" for="branchSwitch">Branch</label>
                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                <select class="erp-switcher-select" id="branchSwitch" name="branch_id" data-erp-autosubmit>
                    @foreach($switchableBranches as $b)
                        <option value="{{ $b->id }}" @selected($currentBranch && $b->id === $currentBranch->id)>{{ $b->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        @if($switchableWarehouses->count() > 1)
            <form class="erp-switcher d-none d-xl-flex" method="POST" action="{{ route('context.warehouse') }}">
                @csrf
                <label class="erp-visually-hidden" for="warehouseSwitch">Warehouse</label>
                <i class="bi bi-box-seam" aria-hidden="true"></i>
                <select class="erp-switcher-select" id="warehouseSwitch" name="warehouse_id" data-erp-autosubmit>
                    @foreach($switchableWarehouses as $w)
                        <option value="{{ $w->id }}" @selected($currentWarehouse && $w->id === $currentWarehouse->id)>{{ $w->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif

        <button class="erp-icon-btn d-none d-md-inline-flex" type="button" data-erp-theme-toggle
                aria-pressed="false" title="Switch appearance">
            <i class="bi bi-moon-stars" aria-hidden="true"></i>
        </button>

        <button class="erp-icon-btn d-none d-xl-inline-flex" type="button" data-erp-density-toggle
                aria-pressed="false" title="Compact rows">
            <i class="bi bi-list-ul" aria-hidden="true"></i>
        </button>

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
                    <span class="erp-chip erp-chip-outline" data-notif-unread>{{ $unread }} unread</span>
                </div>
                <div data-erp-notif-list>
                    <p class="dropdown-item-text erp-notif-loading">Loading latest…</p>
                </div>
                <a class="dropdown-item text-center fw-semibold" href="{{ route('notifications.index') }}">View all notifications</a>
            </div>
        </div>

        <div class="dropdown">
            <button class="erp-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"
                    aria-label="Account menu">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </button>
            <div class="dropdown-menu dropdown-menu-end">
                <div class="dropdown-header">
                    <strong>{{ auth()->user()->name }}</strong>
                    <small class="d-block text-body-secondary">{{ auth()->user()->email }}</small>
                </div>
                @foreach($headerEntries as $entry)
                    @continue(str_contains($entry['route'], '/notifications'))
                    <a class="dropdown-item" href="{{ explode('#', $entry['route'])[0] }}">
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
