@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New role' : 'Edit role')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New role' : 'Edit: '.$role->name }}</h1>
            <p class="erp-page-sub">Tick the exact capabilities this role carries. Changes apply on the user's very next request.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('roles.index') }}">Back to roles</a>
    </div>

    <form method="POST" action="{{ $mode === 'create' ? route('roles.store') : route('roles.update', $role) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Role</h2></header>
                    <div class="mb-3">
                        <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                               value="{{ old('name', $role->name) }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="slug">Code <span class="text-danger">*</span></label>
                        <input class="form-control @error('slug') is-invalid @enderror" id="slug" name="slug"
                               value="{{ old('slug', $role->slug) }}" required
                               {{ $role->is_system ? 'readonly' : '' }}
                               pattern="[a-z0-9][a-z0-9_-]*">
                        <div class="form-text">Lower-case identifier, unique per company.</div>
                        @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="3">{{ old('description', $role->description) }}</textarea>
                        @error('description')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create role' : 'Save changes' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('roles.index') }}">Cancel</a>
                </div>
            </div>

            <div class="col-lg-8">
                <section class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">Permission matrix</h2>
                        <span class="erp-chip erp-chip-soft" data-permission-count>{{ count($assigned) }} selected</span>
                    </header>

                    @error('permissions')<div class="alert alert-danger">{{ $message }}</div>@enderror

                    <div class="erp-matrix">
                        @foreach ($matrix as $module => $permissions)
                            <div class="erp-matrix-module" data-module="{{ $module }}">
                                <header class="erp-matrix-head">
                                    <label class="form-check">
                                        <input class="form-check-input" type="checkbox" data-module-checkall="{{ $module }}">
                                        <span class="form-check-label fw-semibold">{{ ucfirst(str_replace('_', ' ', $module)) }}</span>
                                    </label>
                                    <span class="erp-chip erp-chip-soft" data-module-count="{{ $module }}">
                                        {{ collect($assigned)->intersect($permissions->pluck('id')->all())->count() }}/{{ $permissions->count() }}
                                    </span>
                                </header>
                                <div class="erp-matrix-body">
                                    @foreach ($permissions as $permission)
                                        <label class="erp-matrix-item">
                                            <input class="form-check-input" type="checkbox" name="permissions[]"
                                                   value="{{ $permission->id }}" data-module-item="{{ $module }}"
                                                   @checked(in_array($permission->id, old('permissions', $assigned)))>
                                            <span>
                                                <strong>{{ $permission->resource }}</strong>
                                                <small class="text-body-secondary">· {{ $permission->action }}</small>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
