@extends('layouts.app')

@section('page_title', 'System maintenance')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">System maintenance</h1>
            <p class="erp-page-sub">Safe self-healing operations. Destructive maintenance is never available here.</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Search index</h2>
                    <span class="erp-chip erp-chip-soft">allowed self-heal</span>
                </header>
                <p class="text-body-secondary">
                    Rebuilds the global search index entirely from source tables
                    (users, branches, warehouses, roles, documents). No business
                    data is created or modified.
                </p>
                <p class="mb-3">
                    Current rows:
                    <strong>{{ number_format(\App\Search\SearchIndex::query()->count()) }}</strong>
                </p>
                @if ($perm('maintenance.index'))
                    <form method="POST" action="{{ route('maintenance.rebuild-index') }}">
                        @csrf
                        <button class="btn btn-primary" type="submit">
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Rebuild search index
                        </button>
                    </form>
                @else
                    <p class="text-body-secondary mb-0">You do not have permission to run this operation.</p>
                @endif
            </section>
        </div>

        <div class="col-md-6">
            <section class="erp-card h-100">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">CLI operations</h2>
                </header>
                <p class="text-body-secondary mb-2">Preferred for scheduled or large rebuilds:</p>
                <pre class="erp-pre mb-0">php artisan erp:search:rebuild
php artisan erp:chain-verify
php artisan menu:sync</pre>
            </section>
        </div>
    </div>
@endsection
