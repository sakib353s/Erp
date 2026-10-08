@props(['value', 'label' => null, 'lg' => false])

{{-- StatusBadge (§18.2): semantic colour only — statuses never use a rainbow. --}}
@php($key = strtolower(str_replace([' ', '-'], '_', (string) $value)))
@php($text = $label ?? $value)
<span {{ $attributes->class(['erp-status', 'erp-status-'.$key, 'erp-status-lg' => $lg]) }}>
    {{ t('status.'.$key, $text) }}
</span>
