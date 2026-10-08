@php
    /* §11 — write, or correct, a body the campaigns are built from. */
    $editing = $template !== null;
    $action = $editing ? route('marketing.templates.update', $template) : route('marketing.templates.store');
@endphp

<x-ui.page-header
    :eyebrow="'Marketing · Templates · '.($editing ? $template->code : 'New')"
    :title="$editing ? 'Correct '.$template->name : 'Write a body we can reuse'"
    subtitle="Templates are not campaigns: nothing goes anywhere from this page. A body written here is picked up when a campaign is written, and the campaign keeps the wording it was launched with — so editing this later changes what the *next* campaign says, not what the last one said."
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('marketing.templates') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> All templates
        </a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    @error('code')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What it is, and where it goes</h2>
                <p class="erp-card-sub">The code is how the desk finds it again, and the channel decides whether it is a phone message or an email.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="code">Code</label>
                <input class="form-control @error('code') is-invalid @enderror" type="text" name="code" id="code"
                       value="{{ old('code', $template?->code) }}" maxlength="64" required placeholder="MKT-OFFER-SMS" style="text-transform: uppercase;">
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">One code per channel — the same code may exist on SMS and on email.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" type="text" name="name" id="name"
                       value="{{ old('name', $template?->name) }}" maxlength="120" required placeholder="This month’s offer">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-2">
                <label class="form-label" for="channel">Channel</label>
                <select class="form-select @error('channel') is-invalid @enderror" name="channel" id="channel" required>
                    @foreach ($channels as $key => $row)
                        <option value="{{ $key }}" @selected(old('channel', $template?->channel) === $key)>{{ $row['label'] }}</option>
                    @endforeach
                </select>
                @error('channel')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-3">
                <label class="form-label" for="subject">Subject <span class="erp-td-muted">(email)</span></label>
                <input class="form-control @error('subject') is-invalid @enderror" type="text" name="subject" id="subject"
                       value="{{ old('subject', $template?->subject) }}" maxlength="255">
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-12">
                <label class="form-label" for="body">The body</label>
                <textarea class="form-control @error('body') is-invalid @enderror" name="body" id="body" rows="6" maxlength="4000" required>{{ old('body', $template?->body) }}</textarea>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">
                    @foreach ($placeholders as $placeholder)
                        <code>{{ $placeholder }}</code>@if (! $loop->last) · @endif
                    @endforeach
                    — these are replaced when the message is rendered. Everything else is left alone.
                </div>
            </div>

            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_active" id="is_active" value="1"
                           @checked(old('is_active', $template?->is_active ?? true))>
                    <label class="form-check-label" for="is_active">
                        Available to new campaigns
                        <small class="erp-td-muted d-block">Switching this off keeps the body on file for the campaigns that already used it, and takes it out of the picker.</small>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <div class="erp-form-actions">
        <button class="btn btn-primary" type="submit">
            <i class="bi bi-check2" aria-hidden="true"></i> {{ $editing ? 'Save the changes' : 'Save the body' }}
        </button>
        <a class="btn btn-link" href="{{ route('marketing.templates') }}">Cancel</a>
    </div>
</form>

<x-ui.related-pages />
