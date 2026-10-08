<x-ui.page-header
    eyebrow="Business Management · Notice board"
    title="Write a notice"
    subtitle="Saving and telling everybody are two different acts: a draft is yours until you publish it. The audience you choose is stored with the notice, so “we told the Dhaka outlet” stays true even after somebody joins or leaves.">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('notices.register') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Register
        </a>
    </x-slot:actions>
</x-ui.page-header>

<form method="POST" action="{{ route('notices.store') }}">
    @csrf

    <section class="erp-card erp-card-max">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What it says</h2>
                <p class="erp-card-sub">The title is what people see in their notifications, so it has to carry the message on its own.</p>
            </div>
        </header>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="title">Title</label>
                    <input class="form-control @error('title') is-invalid @enderror" type="text" name="title" id="title"
                           value="{{ old('title') }}" maxlength="191" required>
                    @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="category">Category</label>
                    <select class="form-select @error('category') is-invalid @enderror" name="category" id="category" required>
                        @foreach ($categories as $key => $label)
                            <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="body">The notice</label>
                    <textarea class="form-control @error('body') is-invalid @enderror" name="body" id="body" rows="8" required>{{ old('body') }}</textarea>
                    @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Plain text. Line breaks are kept as written.</div>
                </div>
            </div>
        </div>
    </section>

    <section class="erp-card erp-card-max mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Who it is for</h2>
                <p class="erp-card-sub">Choose the audience, then fill in the picker that belongs to it. Only the picker matching the audience is read.</p>
            </div>
        </header>
        <div class="p-3">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="audience_type">Audience</label>
                    <select class="form-select @error('audience_type') is-invalid @enderror" name="audience_type" id="audience_type" required>
                        @foreach ($modes as $key => $label)
                            <option value="{{ $key }}" @selected(old('audience_type', 'all') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('audience_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <p class="erp-filter-note mb-0">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Everybody means every active member of the company. A notice addressed to a role reaches the people holding it now, and the ledger remembers who those were.
                    </p>
                </div>

                <div class="col-12">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="audience_roles">Roles</label>
                            <select class="form-select @error('audience_roles') is-invalid @enderror" name="audience_roles[]" id="audience_roles" multiple size="6">
                                @foreach ($roles as $role)
                                    <option value="{{ $role->id }}" @selected(in_array($role->id, array_map('intval', (array) old('audience_roles', [])), true))>{{ $role->name }}</option>
                                @endforeach
                            </select>
                            @error('audience_roles')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="audience_branches">Branches</label>
                            <select class="form-select @error('audience_branches') is-invalid @enderror" name="audience_branches[]" id="audience_branches" multiple size="6">
                                @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" @selected(in_array($branch->id, array_map('intval', (array) old('audience_branches', [])), true))>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            @error('audience_branches')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="audience_users">People</label>
                            <select class="form-select @error('audience_users') is-invalid @enderror" name="audience_users[]" id="audience_users" multiple size="6">
                                @foreach ($people as $person)
                                    <option value="{{ $person->id }}" @selected(in_array($person->id, array_map('intval', (array) old('audience_users', [])), true))>{{ $person->name }}</option>
                                @endforeach
                            </select>
                            @error('audience_users')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="requires_acknowledgement" id="requires_acknowledgement" value="1"
                               @checked(old('requires_acknowledgement'))>
                        <label class="form-check-label" for="requires_acknowledgement">Ask everybody to acknowledge it</label>
                    </div>
                    <div class="form-text">A policy notice usually does. The acknowledgement is a row per person against this notice — it survives refresh, login and clearing the inbox.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="expires_at">Stop showing it after (optional)</label>
                    <input class="form-control @error('expires_at') is-invalid @enderror" type="date" name="expires_at" id="expires_at" value="{{ old('expires_at') }}">
                    @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">It stays on the register and in the audit trail either way.</div>
                </div>
            </div>
        </div>
    </section>

    <div class="d-flex gap-2 mt-3">
        <button class="btn btn-outline-secondary" type="submit" name="intent" value="draft">
            <i class="bi bi-save" aria-hidden="true"></i> Save as draft
        </button>
        <button class="btn btn-primary" type="submit" name="intent" value="publish"
                data-confirm="Publish this notice now? Everybody in the audience gets a notification.">
            <i class="bi bi-megaphone" aria-hidden="true"></i> Publish
        </button>
    </div>
</form>
