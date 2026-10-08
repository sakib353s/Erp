@props([
    'rows' => 4,
    'label' => 'Loading…',
])

{{-- LoadingState (§16-51): a skeleton, never a spinner over stale data and never
     a fake row dressed up to look loaded. The rows are placeholders only. --}}
<div {{ $attributes->class(['erp-loading', 'aria-busy' => 'true']) }} role="status" aria-live="polite">
    <span class="visually-hidden">{{ $label }}</span>
    @for ($i = 0; $i < (int) $rows; $i++)
        <div class="erp-skeleton-row">
            <span class="erp-skeleton erp-skeleton-thumb"></span>
            <span class="erp-skeleton erp-skeleton-line"></span>
        </div>
    @endfor
</div>
