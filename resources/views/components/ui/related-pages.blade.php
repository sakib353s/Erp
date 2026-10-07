{{--
    "More in this module" rail.

    Keeps deep capability one click away from the page the user is already on,
    instead of re-inflating the sidebar into the supplied §47 spec tree. Resolves
    from the same permission-filtered set as the nav (memoised per request), so
    it can only ever link to screens the user may open.
--}}
@php
    $nav = app(\App\Domain\Foundation\Services\NavigationBuilder::class);
    $portal = (string) session('tenant.portal', 'erp');
    $links = $nav->related('/'.request()->path(), auth()->user(), $portal);
@endphp

@if(count($links) > 0)
    <nav class="erp-card erp-card-tight" aria-label="More in this module">
        <p class="erp-field-label">More in this module</p>
        <div class="d-flex flex-wrap gap-2">
            @foreach($links as $link)
                <a class="erp-chip erp-chip-outline" href="{{ $link['url'] }}">
                    <i class="bi bi-arrow-up-right" aria-hidden="true"></i>{{ $link['label'] }}
                </a>
            @endforeach
        </div>
    </nav>
@endif
