@extends('layouts.app')

@section('page_title', 'Search')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Search</h1>
            <p class="erp-page-sub">Results are scoped to your branch access and effective permissions.</p>
        </div>
    </div>

    <form class="row g-2 mb-4" method="GET" action="{{ route('search.index') }}" role="search">
        <div class="col-sm-8 col-md-6">
            <label class="visually-hidden" for="q">Search</label>
            <input class="form-control form-control-lg" type="search" id="q" name="q"
                   value="{{ $q }}" placeholder="Search people, branches, warehouses, roles, documents…"
                   autofocus autocomplete="off">
        </div>
        <div class="col-auto">
            <button class="btn btn-primary btn-lg" type="submit">
                <i class="bi bi-search" aria-hidden="true"></i> Search
            </button>
        </div>
    </form>

    @if ($q === '')
        <div class="erp-card">
            <p class="text-body-secondary mb-0">Type at least 2 characters to search.</p>
        </div>
    @elseif ($hits->isEmpty())
        <div class="erp-card">
            <p class="text-body-secondary mb-0">No results match “{{ $q }}” within your access.</p>
        </div>
    @else
        <p class="text-body-secondary">{{ $hits->count() }} result(s) for “{{ $q }}”.</p>

        @foreach ($grouped as $type => $group)
            <section class="erp-card mb-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">{{ ucfirst($type) }}s</h2>
                    <span class="erp-chip erp-chip-soft">{{ $group->count() }}</span>
                </header>
                @foreach ($group as $hit)
                    <a class="erp-list-row text-decoration-none" href="{{ $hit->url }}">
                        <span>
                            <strong>{{ $hit->title }}</strong>
                            @if ($hit->subtitle)
                                <span class="text-body-secondary ms-2">{{ $hit->subtitle }}</span>
                            @endif
                            @if ($hit->excerpt)
                                <br><small class="text-body-secondary">{{ $hit->excerpt }}</small>
                            @endif
                        </span>
                        <i class="bi bi-chevron-right text-body-secondary" aria-hidden="true"></i>
                    </a>
                @endforeach
            </section>
        @endforeach
    @endif
@endsection
