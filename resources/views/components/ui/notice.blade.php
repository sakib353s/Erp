@props([
    'type' => 'info',          // info | success | warning | danger
    'title' => null,
    'dismissible' => false,
])

@php
    // Semantic class only — never a colour that pretends a warning is an error.
    $tone = match ($type) {
        'success' => 'erp-notice-success',
        'warning' => 'erp-notice-warning',
        'danger'  => 'erp-notice-danger',
        default    => 'erp-notice-info',
    };
@endphp

{{-- Notice (§16-51): a page-level state the user must see — a saved change, a
     validation failure, a connection that dropped. Distinct from x-ui.status, which
     marks a record's state; this marks the page's own state. --}}
<div {{ $attributes->class(['erp-notice', $tone]) }} role="alert">
    @if ($title)
        <p class="erp-notice-title">{{ $title }}</p>
    @endif
    <div class="erp-notice-body">{{ $slot }}</div>
    @if ($dismissible)
        <button type="button" class="erp-notice-close" aria-label="Dismiss" onclick="this.closest('.erp-notice').remove()">×</button>
    @endif
</div>
