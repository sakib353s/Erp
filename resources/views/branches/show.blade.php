@extends('layouts.app')

@section('page_title', 'Branch details')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $branch->name }} <span class="erp-chip erp-chip-soft">{{ $branch->code }}</span></h1>
            <p class="erp-page-sub">
                @if($branch->is_default)Default branch · @endif
                Operating status: {{ ucwords(str_replace('_', ' ', $branch->operating_status ?? 'active')) }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('branches.index') }}">Back</a>
            @if ($perm('branches.update'))
                <a class="btn btn-primary" href="{{ route('branches.edit', $branch) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
            @if ($perm('branches.delete') && ! $branch->is_default)
                <form method="POST" action="{{ route('branches.destroy', $branch) }}"
                      data-confirm="Delete branch {{ $branch->name }}? It must have no users and no warehouses. This cannot be undone.">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Profile</h2></header>
                <dl class="erp-dl">
                    <dt>Code</dt><dd><code>{{ $branch->code }}</code></dd>
                    <dt>Phone</dt><dd>{{ $branch->phone ?: '—' }}</dd>
                    <dt>E-mail</dt><dd>{{ $branch->email ?: '—' }}</dd>
                    <dt>Address</dt>
                    <dd>
                        @php($address = collect([$branch->address_line1, $branch->address_line2, $branch->area, $branch->district, $branch->postal_code])->filter()->implode(', '))
                        {{ $address ?: '—' }}
                    </dd>
                    <dt>Active</dt><dd>{{ $branch->is_active ? 'Yes' : 'No' }}</dd>
                    <dt>Users here</dt><dd>{{ $branch->users_count ?? $branch->users()->count() }}</dd>
                </dl>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Warehouses in this branch</h2>
                </header>
                @forelse(\App\Domain\Foundation\Warehouse::query()->where('branch_id', $branch->id)->orderBy('name')->get() as $w)
                    <div class="erp-list-row">
                        <span><code>{{ $w->code }}</code> {{ $w->name }}</span>
                        <span class="erp-status {{ $w->is_active ? 'erp-status-active' : 'erp-status-disabled' }}">
                            {{ $w->is_active ? 'active' : 'inactive' }}
                        </span>
                    </div>
                @empty
                    <p class="text-body-secondary">No warehouses in this branch yet.</p>
                @endforelse
            </section>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
