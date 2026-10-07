{{--
    NavigationTree renderer (v2).

    Receives the already permission-filtered, already curated forest from
    NavigationBuilder — this view only renders. It used to recurse into the raw
    §47 catalog, which is how verb-first action leaves ("Bulk Print Invoice",
    "Add Session") ended up as first-class sidebar links pointing at their
    parent's page (§18.3 violation). Now:
      · action leaves are seeded with location='action' and never arrive here;
      · a module group opens when it holds the active page ("active trail");
      · a module with fewer than two children renders as a plain link, so the
        sidebar never shows a one-item accordion.
--}}
@foreach($nodes as $node)
    @php
        $children = $node['children'] ?? [];
        $activeTrail = ! empty($node['current'])
            || collect($children)->contains(fn ($child) => ! empty($child['current']));
        $grouped = count($children) > 0;
        $url = $node['url'] ?? null;
    @endphp

    @if($grouped)
        <div class="erp-nav-group">
            <button class="erp-nav-group-btn {{ $activeTrail ? 'is-active has-active-trail' : '' }}"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#nav-{{ $node['id'] ?? $node['code'] }}"
                    aria-expanded="{{ $activeTrail ? 'true' : 'false' }}"
                    aria-controls="nav-{{ $node['id'] ?? $node['code'] }}">
                <i class="bi {{ $node['icon'] ?? 'bi-folder' }} erp-nav-icon" aria-hidden="true"></i>
                <span>{{ $tr($node['label_key'] ?? null, $node['label']) }}</span>
                <i class="bi bi-chevron-right erp-nav-caret" aria-hidden="true"></i>
            </button>

            <div class="collapse {{ $activeTrail ? 'show' : '' }}" id="nav-{{ $node['id'] ?? $node['code'] }}">
                <div class="erp-nav-sub">
                    @foreach($children as $child)
                        <a class="erp-nav-link {{ ! empty($child['current']) ? 'active' : '' }}"
                           href="{{ $child['url'] ?? $child['route'] }}"
                           @if(! empty($child['current'])) aria-current="page" @endif>
                            <span>{{ $tr($child['label_key'] ?? null, $child['label']) }}</span>
                        </a>
                    @endforeach

                    @if(($node['overflow_count'] ?? 0) > 0)
                        <button class="erp-nav-link" type="button" data-palette-open
                                title="Search every page in this module">
                            <i class="bi bi-plus-circle erp-nav-icon" aria-hidden="true"></i>
                            <span>{{ $node['overflow_count'] }} more — search</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @elseif($url !== null)
        <a class="erp-nav-link {{ ! empty($node['current']) ? 'active' : '' }}" href="{{ $url }}"
           @if(! empty($node['current'])) aria-current="page" @endif>
            <i class="bi {{ $node['icon'] ?? 'bi-dot' }} erp-nav-icon" aria-hidden="true"></i>
            <span>{{ $tr($node['label_key'] ?? null, $node['label']) }}</span>
        </a>
    @endif
@endforeach
