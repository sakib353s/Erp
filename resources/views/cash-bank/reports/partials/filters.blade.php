@php($extra = $extra ?? '')
@php($branches = $branches ?? collect())

{{-- One filter bar for the whole §08 report family: the same window, the same
     branch, the same CSV that carries the rows the screen is showing. --}}
<form class="erp-filterbar" method="GET" action="{{ $action }}" role="search">
    <div class="erp-filter">
        <label class="form-label" for="from">From</label>
        <input class="form-control" id="from" name="from" type="date" value="{{ $filters['from'] }}">
    </div>
    <div class="erp-filter">
        <label class="form-label" for="to">To</label>
        <input class="form-control" id="to" name="to" type="date" value="{{ $filters['to'] }}">
    </div>
    @if ($branches->isNotEmpty())
        <div class="erp-filter">
            <label class="form-label" for="branch">Branch</label>
            <select class="form-select" id="branch" name="branch">
                <option value="">Every branch</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected(($filters['branch'] ?? null) === $branch->id)>
                        {{ $branch->name }}{{ $branch->is_default ? ' (head office)' : '' }}
                    </option>
                @endforeach
            </select>
        </div>
    @endif
    {!! $extra !!}
    <div class="erp-filterbar-actions">
        <a class="btn btn-link" href="{{ $action }}">Reset</a>
        <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Run the report</button>
        <a class="btn btn-outline-secondary"
           href="{{ $action.(str_contains($action, '?') ? '&' : '?').http_build_query(request()->except('format') + ['format' => 'csv']) }}">
            <i class="bi bi-download" aria-hidden="true"></i> CSV
        </a>
    </div>
</form>
