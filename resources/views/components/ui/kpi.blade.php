@props([
    'label',
    'value',
    'icon' => null,
    'hint' => null,
    'delta' => null,
    'trend' => null,
    'hero' => false,
    'href' => null,
])

{{-- KpiCard (§18.2): one number, its meaning, its source. Values are always
     server-computed — a widget never invents a figure (global invariant). --}}
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" @endif
   {{ $attributes->class(['erp-kpi', 'erp-kpi-hero' => $hero, 'text-decoration-none' => (bool) $href]) }}>
    <p class="erp-kpi-label">
        @if($icon)<i class="bi {{ $icon }}" aria-hidden="true"></i>@endif
        {{ $label }}
    </p>
    <p class="erp-kpi-value">{{ $value }}</p>

    @if($delta || $trend)
        <span class="erp-kpi-delta {{ $trend === 'up' ? 'up' : ($trend === 'down' ? 'down' : '') }}">
            @if($trend)<i class="bi bi-arrow-{{ $trend === 'up' ? 'up' : ($trend === 'down' ? 'down' : 'right') }}" aria-hidden="true"></i>@endif
            {{ $delta }}
        </span>
    @endif

    @if($hint)
        <p class="erp-kpi-foot">{{ $hint }}</p>
    @endif
</{{ $tag }}>
