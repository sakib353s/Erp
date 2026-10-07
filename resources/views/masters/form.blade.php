@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New '.$entry['label'] : 'Edit '.$entry['label'])

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New '.$entry['label'] : 'Edit: '.($record->name ?? $record->code ?? 'row') }}</h1>
            <p class="erp-page-sub">Fields follow the master-data catalog; codes are unique per company.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('masters.'.$type.'.index') }}">Back to {{ strtolower($entry['label']) }}</a>
    </div>

    <form method="POST"
          action="{{ $mode === 'create' ? route('masters.'.$type.'.store') : route('masters.'.$type.'.update', $record) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-8">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Details</h2></header>
                    <div class="row g-3">
                        @foreach($entry['fields'] as $field => $meta)
                            @continue(($meta['type'] ?? 'text') === 'boolean')
                            <div class="col-md-6">
                                <label class="form-label" for="{{ $field }}">
                                    {{ $meta['label'] ?? $field }}
                                    @if($meta['required'] ?? false)<span class="text-danger">*</span>@endif
                                </label>

                                @if(($meta['type'] ?? 'text') === 'select')
                                    <select class="form-select @error($field) is-invalid @enderror" id="{{ $field }}" name="{{ $field }}"
                                            @if($meta['required'] ?? false) required @endif>
                                        <option value="">— select —</option>
                                        @foreach($meta['options'] ?? [] as $option)
                                            <option value="{{ $option }}" @selected(old($field, $record->getAttribute($field)) === $option)>
                                                {{ ucfirst($option) }}
                                            </option>
                                        @endforeach
                                    </select>
                                @elseif(($meta['type'] ?? 'text') === 'date')
                                    <input class="form-control @error($field) is-invalid @enderror" type="date" id="{{ $field }}"
                                           name="{{ $field }}" value="{{ old($field, $record->getAttribute($field)) }}"
                                           @if($meta['required'] ?? false) required @endif>
                                @elseif(($meta['type'] ?? 'text') === 'number')
                                    <input class="form-control @error($field) is-invalid @enderror" type="number" id="{{ $field }}"
                                           name="{{ $field }}" value="{{ old($field, $record->getAttribute($field)) }}"
                                           step="{{ $meta['step'] ?? 'any' }}"
                                           @if($meta['required'] ?? false) required @endif>
                                @else
                                    <input class="form-control @error($field) is-invalid @enderror" type="text" id="{{ $field }}"
                                           name="{{ $field }}"
                                           value="{{ old($field, $record->getAttribute($field)) }}"
                                           maxlength="{{ $meta['max'] ?? 191 }}"
                                           @if($meta['upper'] ?? false) class="form-control text-uppercase" @endif
                                           @if($meta['required'] ?? false) required @endif>
                                @endif

                                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Flags</h2></header>
                    @foreach($entry['fields'] as $field => $meta)
                        @if(($meta['type'] ?? 'text') === 'boolean')
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="{{ $field }}"
                                       name="{{ $field }}" value="1"
                                       @checked(old($field, $record->getAttribute($field) ?? ($field === 'is_active')))>
                                <label class="form-check-label" for="{{ $field }}">{{ $meta['label'] ?? $field }}</label>
                            </div>
                            @error($field)<div class="text-danger small">{{ $message }}</div>@enderror
                        @endif
                    @endforeach
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create' : 'Save changes' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('masters.'.$type.'.index') }}">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
