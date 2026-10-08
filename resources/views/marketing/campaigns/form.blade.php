@php
    /* §11 — write a campaign down, or correct one that has not gone out yet. */
    $editing = $campaign !== null;
    $action = $editing ? route('marketing.campaigns.update', $campaign) : route('marketing.campaigns.store');
    $from = $fromTemplate ?? null;
    $selectedChannel = old('channel', $editing ? $campaign->channel : ($from?->channel ?? $preselected));
    $selectedAudience = old('audience', $editing ? $campaign->audience : ($preselectedAudience ?? \App\Domain\Marketing\MarketingCampaign::AUDIENCE_ALL));
    $selectedTemplate = old('template_id', $editing ? $campaign->template_id : $from?->id);
    $body = old('body', $editing ? $campaign->body : ($from?->body ?? ''));
    $subject = old('subject', $editing ? $campaign->subject : ($from?->subject ?? ''));
@endphp

<x-ui.page-header
    :eyebrow="'Marketing · Campaigns · '.($editing ? 'Edit' : 'New')"
    :title="$editing ? 'Correct '.$campaign->code : 'Write a campaign down'"
    :subtitle="$editing
        ? 'A campaign that has not gone out is still a plan: change the wording, the audience rule, the price per message or the time on the clock. Once it is launched it is history — what went to whom is a row, and this form will not rewrite it.'
        : 'Nothing is sent from this form. A campaign is a draft until somebody launches it, and launching is when the audience rule is expanded, the opt-outs are honoured and the messages are handed to the outbox.'"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ $editing ? route('marketing.campaigns.show', $campaign) : route('marketing.campaigns.index') }}">
            <i class="bi bi-arrow-left" aria-hidden="true"></i> Back
        </a>
    </x-slot:actions>
</x-ui.page-header>

@if ($from !== null && ! $editing)
    <div class="erp-note erp-note-info mb-3">
        <i class="bi bi-file-text" aria-hidden="true"></i>
        <div>
            <strong>Starting from “{{ $from->name }}”.</strong>
            The body below is that template's wording — edit it freely. The link to the template is kept so the report can say which body this campaign used.
        </div>
    </div>
@endif

<form method="POST" action="{{ $action }}">
    @csrf
    @if ($editing)
        @method('PUT')
    @endif

    @error('body')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What it is</h2>
                <p class="erp-card-sub">One channel per campaign: an SMS and an email are different messages, not two settings of the same one.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="name">Name</label>
                <input class="form-control @error('name') is-invalid @enderror" type="text" name="name" id="name"
                       value="{{ old('name', $campaign?->name) }}" maxlength="160" required placeholder="Eid offer — Dhaka customers">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-3">
                <label class="form-label" for="channel">Channel</label>
                <select class="form-select @error('channel') is-invalid @enderror" name="channel" id="channel" required>
                    @foreach ($channels as $key => $row)
                        <option value="{{ $key }}" @selected($selectedChannel === $key)>{{ $row['label'] }}</option>
                    @endforeach
                </select>
                @error('channel')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">SMS and WhatsApp write to the phone; email to the address.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="objective">What it is for</label>
                <input class="form-control @error('objective') is-invalid @enderror" type="text" name="objective" id="objective"
                       value="{{ old('objective', $campaign?->objective) }}" maxlength="191" placeholder="Win back customers who stopped buying">
                @error('objective')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">What they will read</h2>
                <p class="erp-card-sub">Written once, rendered per recipient at launch — the placeholders below are the only ones that will be replaced.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="template_id">Start from a template</label>
                <select class="form-select @error('template_id') is-invalid @enderror" name="template_id" id="template_id">
                    <option value="">— written here —</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}" @selected((int) $selectedTemplate === (int) $template->id)>
                            {{ $template->channel }} · {{ $template->name }}{{ $template->company_id === null ? ' (system)' : '' }}
                        </option>
                    @endforeach
                </select>
                @error('template_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">The link is kept for the report; the body below is what actually goes out.</div>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="subject">Subject <span class="erp-td-muted">(email only)</span></label>
                <input class="form-control @error('subject') is-invalid @enderror" type="text" name="subject" id="subject"
                       value="{{ $subject }}" maxlength="255">
                @error('subject')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Required on email — it is the only part some people read.</div>
            </div>

            <div class="col-12">
                <label class="form-label" for="body">The message</label>
                <textarea class="form-control @error('body') is-invalid @enderror" name="body" id="body" rows="7" maxlength="4000" required>{{ $body }}</textarea>
                @error('body')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">
                    Available placeholders:
                    @foreach ($placeholders as $placeholder)
                        <code>{{ $placeholder }}</code>@if (! $loop->last) · @endif
                    @endforeach
                    — anything else stays exactly as typed, braces and all.
                </div>
            </div>
        </div>
    </div>

    <div class="erp-card mb-3">
        <div class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Who it goes to</h2>
                <p class="erp-card-sub">A rule, not a snapshot: it is expanded at launch, so a customer who opted out in the meantime is skipped.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12">
                @foreach ($audiences as $key => $rule)
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="radio" name="audience" id="audience_{{ $key }}"
                               value="{{ $key }}" @checked($selectedAudience === $key)>
                        <label class="form-check-label" for="audience_{{ $key }}">
                            <strong><i class="bi {{ $rule['icon'] }} me-1" aria-hidden="true"></i>{{ $rule['label'] }}</strong>
                            <small class="erp-td-muted d-block">{{ $rule['blurb'] }}</small>
                        </label>
                    </div>
                @endforeach
                @error('audience')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>

            <div class="col-md-4">
                <label class="form-label" for="audience_days">Window (days)</label>
                <input class="form-control @error('audience_days') is-invalid @enderror" type="number" min="1" max="365"
                       name="audience_days" id="audience_days"
                       value="{{ old('audience_days', $campaign?->audience_days ?? 90) }}">
                @error('audience_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Used by “bought recently” and “gone quiet”.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="cost_per_message">Cost per message</label>
                <input class="form-control erp-num @error('cost_per_message') is-invalid @enderror" type="number" step="0.0001" min="0"
                       name="cost_per_message" id="cost_per_message"
                       value="{{ old('cost_per_message', $campaign?->cost_per_message ?? '0') }}">
                @error('cost_per_message')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">What the provider charges you. Frozen on each recipient at launch.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label" for="attribution_days">Count orders for (days)</label>
                <input class="form-control @error('attribution_days') is-invalid @enderror" type="number" min="1" max="90"
                       name="attribution_days" id="attribution_days"
                       value="{{ old('attribution_days', $campaign?->attribution_days ?? 7) }}">
                @error('attribution_days')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Invoices these recipients raise inside the window after launch.</div>
            </div>

            <div class="col-12">
                <details class="erp-inline-form">
                    <summary class="btn btn-sm btn-outline-secondary">Hand-picked list ({{ $customers->count() }} customer(s))</summary>
                    <p class="form-text mt-2 mb-1">
                        Only used when the rule above is “A hand-picked list”. Leave it alone for every other rule — the audience is worked out from invoices instead.
                    </p>
                    <div class="row g-2" style="max-height: 320px; overflow-y: auto;">
                        @foreach ($customers as $customer)
                            <div class="col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="customer_ids[]" value="{{ $customer->id }}"
                                           id="customer_{{ $customer->id }}" @checked(in_array($customer->id, array_map('intval', (array) old('customer_ids', []))))>
                                    <label class="form-check-label" for="customer_{{ $customer->id }}">
                                        {{ $customer->name }}
                                        <small class="erp-td-muted d-block">
                                            {{ $customer->phone ?? 'no phone' }} · {{ $customer->recent_invoices ?? 0 }} invoice(s) in 90 days
                                        </small>
                                    </label>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            </div>

            <div class="col-12">
                <label class="form-label" for="notes">Notes for whoever reads this next</label>
                <textarea class="form-control" name="notes" id="notes" rows="2" maxlength="500">{{ old('notes', $campaign?->notes) }}</textarea>
            </div>
        </div>
    </div>

    <div class="erp-form-actions">
        <button class="btn btn-primary" type="submit" data-confirm="{{ $editing ? 'Save these changes?' : 'Write this campaign down as a draft?' }}">
            <i class="bi bi-check2" aria-hidden="true"></i> {{ $editing ? 'Save the changes' : 'Save as a draft' }}
        </button>
        <a class="btn btn-link" href="{{ $editing ? route('marketing.campaigns.show', $campaign) : route('marketing.campaigns.index') }}">Cancel</a>
        @if (! $editing)
            <span class="erp-td-muted ms-2">You can put it on the clock or launch it from its own page.</span>
        @endif
    </div>
</form>

<x-ui.related-pages />
