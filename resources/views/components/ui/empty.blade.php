@props([
    'title' => 'Nothing here yet',
    'text' => null,
    'icon' => 'bi-inbox',
    'action' => null,
    'href' => null,
])

{{-- EmptyState (§18.2): professional, explains the next step (§18.6) — never
     a bare "no data" and never a fake placeholder row. --}}
<div {{ $attributes->class(['erp-empty']) }}>
    <span class="erp-empty-icon"><i class="bi {{ $icon }}" aria-hidden="true"></i></span>
    <p>{{ $title }}</p>
    @if($text)<small>{{ $text }}</small>@endif
    @if($action)
        <div class="erp-empty-actions">
            <a class="btn btn-primary btn-sm" href="{{ $href }}">{{ $action }}</a>
        </div>
    @endif
</div>
