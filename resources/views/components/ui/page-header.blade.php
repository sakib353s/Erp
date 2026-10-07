@props([
    'title' => null,
    'subtitle' => null,
    'eyebrow' => null,
    'pin' => false,
    'meta' => null,
])

@php
    /*
     | PageHeader (§18.2). Resolves the pin target itself so pages never have to
     | know about menu_item ids — and so a page the user cannot open can never
     | offer a pin. NavigationBuilder is memoised per request.
     */
    $pinItemId = null;
    $isPinned = false;

    if ($pin) {
        $nav = app(\App\Domain\Foundation\Services\NavigationBuilder::class);
        $pinItemId = $nav->currentMenuItemId('/'.request()->path(), auth()->user());
        $isPinned = $pinItemId !== null
            && \App\Domain\Foundation\MenuItemFavorite::query()
                ->where('user_id', auth()->id())
                ->where('menu_item_id', $pinItemId)
                ->exists();
    }
@endphp

<header {{ $attributes->class(['erp-page-head']) }}>
    <div class="erp-page-head-main">
        @if($eyebrow)
            <p class="erp-eyebrow">{{ $eyebrow }}</p>
        @endif

        <h1 class="erp-h1">{{ $title ?? $__env->getSection('page_title', 'Dashboard') }}</h1>

        @if($subtitle)
            <p class="erp-page-sub">{{ $subtitle }}</p>
        @endif

        @if($meta)
            <div class="erp-meta-row">{{ $meta }}</div>
        @endif
    </div>

    <div class="erp-page-head-actions">
        {{ $actions ?? '' }}

        @if($pinItemId)
            <form method="POST" action="{{ route('navigation.pin') }}">
                @csrf
                <input type="hidden" name="menu_item_id" value="{{ $pinItemId }}">
                <button class="erp-icon-btn" type="submit"
                        title="{{ $isPinned ? 'Unpin this page' : 'Pin this page to Pinned' }}"
                        aria-label="{{ $isPinned ? 'Unpin this page' : 'Pin this page' }}"
                        aria-pressed="{{ $isPinned ? 'true' : 'false' }}">
                    <i class="bi {{ $isPinned ? 'bi-star-fill' : 'bi-star' }}" aria-hidden="true"></i>
                </button>
            </form>
        @endif
    </div>
</header>
