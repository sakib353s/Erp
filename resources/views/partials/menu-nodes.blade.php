{{-- DB-driven menu renderer: active + permission-filtered upstream (NavigationBuilder).
     `planned` items never reach this view (clarification C1). Recursive by design. --}}
@forelse($nodes as $node)
    @php
        $deepActive = function ($n) use (&$deepActive) {
            if (! empty($n['current'])) {
                return true;
            }
            foreach (($n['children'] ?? []) as $child) {
                if ($deepActive($child)) {
                    return true;
                }
            }

            return false;
        };
        $isActive = $deepActive($node);
        $hasChildren = ! empty($node['children']);
        $hasRoute = ! empty($node['route']);
    @endphp

    @if($hasChildren && ! $hasRoute)
        {{-- Collapsible group (module headers such as Sales / Settings) --}}
        <div class="erp-nav-group">
            <button class="erp-nav-group-btn {{ $isActive ? 'is-active' : '' }}" type="button"
                    data-bs-toggle="collapse" data-bs-target="#navg-{{ $node['id'] }}"
                    aria-expanded="{{ $isActive ? 'true' : 'false' }}">
                @if($node['icon'] ?? null)<i class="bi {{ $node['icon'] }}" aria-hidden="true"></i>@endif
                <span>{{ $tr($node['label_key'], $node['label']) }}</span>
                <i class="bi bi-chevron-down erp-nav-caret" aria-hidden="true"></i>
            </button>
            <div class="collapse {{ $isActive ? 'show' : '' }}" id="navg-{{ $node['id'] }}">
                <div class="erp-nav-sub">
                    @include('partials.menu-nodes', ['nodes' => $node['children']])
                </div>
            </div>
        </div>
    @else
        @if($hasRoute)
            <a class="erp-nav-link {{ ! empty($node['current']) ? 'active' : '' }}"
               href="{{ strtok($node['route'], '#') }}">
                @if($node['icon'] ?? null)<i class="bi {{ $node['icon'] }}" aria-hidden="true"></i>@endif
                <span>{{ $tr($node['label_key'], $node['label']) }}</span>
            </a>
        @endif
        @if($hasChildren)
            <div class="erp-nav-sub erp-nav-sub-flat">
                @include('partials.menu-nodes', ['nodes' => $node['children']])
            </div>
        @endif
    @endif
@empty
    <p class="erp-nav-empty">No navigation entries are available for your access.</p>
@endforelse
