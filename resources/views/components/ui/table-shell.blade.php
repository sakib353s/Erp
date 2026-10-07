@props([
    'title' => null,
    'count' => null,
    'stack' => true,
    'padding' => true,
    'bulk' => false,
])

{{-- DataTable shell (§18.2): sticky header, honest counts, mobile card
     stacking (§18.4) and one bulk-action surface instead of a button wall. --}}
<div class="erp-table-shell" data-erp-table>
    @if($title || isset($tools))
        <div class="erp-card-head {{ $padding ? 'px-3 pt-3' : '' }}">
            <h2 class="erp-card-title">
                {{ $title }}
                @if($count !== null)<span class="erp-chip erp-chip-outline">{{ $count }}</span>@endif
            </h2>
            @isset($tools)<div class="erp-card-actions">{{ $tools }}</div>@endisset
        </div>
    @endif

    @if($bulk)
        <div class="erp-bulkbar" data-erp-bulkbar>
            <i class="bi bi-check2-square" aria-hidden="true"></i>
            <strong><span data-erp-bulk-count>0</span></strong> selected
            <div class="erp-bulkbar-actions">{{ $bulkActions ?? '' }}</div>
        </div>
    @endif

    <div class="erp-table-scroll">
        <table {{ $attributes->class(['table erp-table', 'erp-table-stack' => $stack]) }}>
            {{ $slot }}
        </table>
    </div>

    @isset($footer)
        <div class="erp-table-foot">{{ $footer }}</div>
    @endisset
</div>
