@php
    /* §12-16 — somebody added to the register before their first visit. */
@endphp

<x-ui.page-header
    eyebrow="Business Management · Visitors · Register"
    title="Add somebody to the visitor register"
    subtitle="The register is how the gate recognises a returning visitor instead of typing them in again. A name is enough to start; a phone number is what makes the next visit find this same record, which is what keeps one person's history in one place."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('business.visitors.people') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> The register
        </a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ route('business.visitors.people.store') }}">
    @csrf

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The person</h2>
                <p class="erp-card-sub">A name, and whatever else the gate may need to match later.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" type="text" name="name" id="name" value="{{ old('name') }}" required>
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" type="text" name="phone" id="phone" value="{{ old('phone') }}" placeholder="01XXXXXXXXX">
                <div class="form-text">Two visits with the same phone are one person, not two.</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="email">Email</label>
                <input class="form-control @error('email') is-invalid @enderror" type="email" name="email" id="email" value="{{ old('email') }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-4">
                <label class="form-label" for="organisation">Organisation</label>
                <input class="form-control" type="text" name="organisation" id="organisation" value="{{ old('organisation') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="id_type">Paper shown</label>
                <select class="form-select" name="id_type" id="id_type">
                    <option value="">— none recorded —</option>
                    @foreach ($idTypes as $key => $label)
                        <option value="{{ $key }}" @selected(old('id_type') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="id_number">Number on the paper</label>
                <input class="form-control" type="text" name="id_number" id="id_number" value="{{ old('id_number') }}">
                <div class="form-text">Shown masked on the register.</div>
            </div>

            <div class="col-12">
                <label class="form-label" for="notes">Notes</label>
                <textarea class="form-control" name="notes" id="notes" rows="3" maxlength="500">{{ old('notes') }}</textarea>
            </div>
        </div>
    </div>

    <div class="erp-form-actions">
        <button class="btn btn-primary" type="submit" data-confirm="Add this person to the register?">
            <i class="bi bi-person-plus" aria-hidden="true"></i> Add to the register
        </button>
        <a class="btn btn-link" href="{{ route('business.visitors.people') }}">Cancel</a>
    </div>
</form>

<x-ui.related-pages />
