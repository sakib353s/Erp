@php
    /* §12-03/04/09/10 — one record: what it says, what it is worth, when it runs
       out, which papers prove it, and everything that has ever happened to it. */
    $state = $record->state();
    $days = $record->daysLeft();

    $tone = match ($state) {
        'expired', 'overdue' => 'erp-note-danger',
        'expiring', 'due_soon' => 'erp-note-warn',
        'valid' => 'erp-note-ok',
        default => 'erp-note-info',
    };

    $icon = match ($state) {
        'expired', 'overdue' => 'bi-exclamation-triangle',
        'expiring', 'due_soon' => 'bi-hourglass-split',
        'valid' => 'bi-check2-circle',
        'retired' => 'bi-archive',
        default => 'bi-question-circle',
    };

    $message = match ($state) {
        'expired' => 'Expired '.abs((int) $days).' day(s) ago, on '.$record->expires_on->format('d M Y').'. A lapsed paper is a decision somebody has to take.',
        'overdue' => 'The deadline was '.$record->due_on->format('d M Y').' — '.abs((int) $days).' day(s) ago — and it is not marked done.',
        'expiring' => 'Runs out on '.$record->expires_on->format('d M Y').' — '.$days.' day(s) left. Renew it while the register still has time to be useful.',
        'due_soon' => 'Due on '.$record->due_on->format('d M Y').' — '.$days.' day(s) left.',
        'valid' => $record->due_on
            ? 'Next deadline '.$record->due_on->format('d M Y').' — '.$days.' day(s) away.'
            : 'In force until '.$record->expires_on->format('d M Y').' — '.$days.' day(s) left.',
        'retired' => 'Retired'.($record->retired_on ? ' on '.$record->retired_on->format('d M Y') : '').'. It stays on the register and in the audit trail.',
        default => 'No date on file. That is normal for a TIN, a number or a logo — and worth a second look for anything else.',
    };

    $config = $registry->config($record->kind);
@endphp

<x-ui.page-header
    eyebrow="Business Management · {{ $config['plural'] }}"
    title="{{ $record->title }}"
    subtitle="{{ $record->reference_no ? $record->reference_no.' · ' : '' }}{{ $record->counterparty ?: ($record->issuer ?: 'No issuer recorded') }}"
    :pin="true">
    <x-slot:actions>
        <a class="btn btn-outline-secondary" href="{{ route('records.kind', $registry->slug($record->kind)) }}">
            <i class="bi {{ $config['icon'] }}" aria-hidden="true"></i> All {{ strtolower($config['plural']) }}
        </a>
        @if ($config['validity'])
            <a class="btn btn-outline-secondary" href="{{ route('compliance.renewals', ['kind' => $record->kind]) }}">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i> Renewals
            </a>
        @endif
    </x-slot:actions>
</x-ui.page-header>

<div class="erp-note {{ $tone }} mb-3">
    <i class="bi {{ $icon }}" aria-hidden="true"></i>
    <div>
        <div class="mb-1"><x-ui.status :value="$state" :label="$record->stateLabel()" /></div>
        <div>{{ $message }}</div>
    </div>
</div>

<div class="erp-split">
    <div class="erp-split-main">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">What the register holds</h2>
                @if ($record->isRetired())
                    <div class="erp-card-actions"><span class="erp-chip erp-chip-outline">Retired</span></div>
                @endif
            </header>
            <div class="px-3 pb-3">
                <dl class="erp-dl erp-dl-tight">
                    <dt>Kind</dt>
                    <dd>{{ $record->kindLabel() }}</dd>

                    @if ($config['reference'])
                        <dt>{{ $config['reference_label'] }}</dt>
                        <dd>{{ $record->reference_no ?: '—' }}</dd>
                    @endif

                    @if ($config['issuer'])
                        <dt>{{ $config['issuer_label'] }}</dt>
                        <dd>{{ $record->issuer ?: '—' }}</dd>
                    @endif

                    @if ($config['party'])
                        <dt>{{ $config['party_label'] }}</dt>
                        <dd>{{ $record->counterparty ?: '—' }}</dd>
                    @endif

                    @if ($config['value'])
                        <dt>{{ $config['value_label'] }}</dt>
                        <dd>{{ $record->value_amount !== null ? '৳'.number_format((float) $record->value_amount, 2) : '—' }}</dd>
                    @endif

                    @if ($config['issued'])
                        <dt>Issued on</dt>
                        <dd>{{ $record->issued_on?->format('d M Y') ?? '—' }}</dd>
                    @endif

                    @if ($config['validity'])
                        <dt>Runs from</dt>
                        <dd>{{ $record->starts_on?->format('d M Y') ?? '—' }}</dd>
                        <dt>Expires on</dt>
                        <dd>{{ $record->expires_on?->format('d M Y') ?? '—' }}</dd>
                    @endif

                    @if ($config['due'])
                        <dt>Next due</dt>
                        <dd>{{ $record->due_on?->format('d M Y') ?? 'Nothing pending' }}</dd>
                        <dt>Comes round</dt>
                        <dd>{{ $registry->cadenceLabel($record->repeat_months) }}</dd>
                        <dt>Last done</dt>
                        <dd>{{ $record->last_completed_on?->format('d M Y') ?? '—' }}</dd>
                    @endif

                    <dt>Branch</dt>
                    <dd>{{ $record->branch?->name ?? 'Company-wide' }}</dd>

                    <dt>Recorded by</dt>
                    <dd>{{ $record->creator?->name ?? '—' }}{{ $record->created_at ? ' on '.$record->created_at->format('d M Y') : '' }}</dd>

                    @if ($record->notes)
                        <dt>Notes</dt>
                        <dd>{{ $record->notes }}</dd>
                    @endif
                </dl>
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Papers filed against this record</h2>
                    <p class="erp-card-sub">The file itself lives in the <a href="{{ $libraryUrl }}">document library</a> — sniffed, checksummed and audited. This is what it is filed under.</p>
                </div>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $record->files->count() }} file(s)</span>
                </div>
            </header>
            <div class="px-3 pb-3">
                @forelse ($record->files as $file)
                    <div class="erp-list-row">
                        <div class="erp-list-row-main">
                            <span class="erp-cell-strong">{{ $file->label() }}</span>
                            <div class="erp-td-muted">
                                {{ $file->document?->mime_type ?? 'file' }}
                                @if ($file->attacher)
                                    · filed by {{ $file->attacher->name }}
                                @endif
                                @if ($file->created_at)
                                    on {{ $file->created_at->format('d M Y') }}
                                @endif
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            @if ($file->document && $perm('documents.download'))
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('documents.download', $file->document) }}">
                                    <i class="bi bi-download" aria-hidden="true"></i> Download
                                </a>
                            @endif
                            @if ($canManage && $file->document)
                                <form method="POST" action="{{ route('records.detach', [$record, $file->document]) }}"
                                      data-confirm="Unfile “{{ $file->label() }}”? The file stays in the document library.">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit">
                                        <i class="bi bi-x-lg" aria-hidden="true"></i> Unfile
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-ui.empty
                        title="No paper filed yet"
                        text="Upload the scan into the document library, then file it here — a register entry that cannot show its paper is a note, not a record."
                        icon="bi-paperclip" />
                @endforelse

                @if ($canManage && $attachable->isNotEmpty())
                    <form class="erp-inline-form mt-3" method="POST" action="{{ route('records.attach', $record) }}">
                        @csrf
                        <div class="row g-2 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label" for="document_id">File it from the library</label>
                                <select class="form-select" name="document_id" id="document_id" required>
                                    <option value="">Choose a file…</option>
                                    @foreach ($attachable as $document)
                                        <option value="{{ $document->id }}">
                                            {{ $document->original_name }} ({{ $document->purpose }}{{ $document->extension ? ', '.$document->extension : '' }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="label">What is it?</label>
                                <input class="form-control" type="text" name="label" id="label" maxlength="120"
                                       placeholder="Signed copy, 2026 renewal…">
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-outline-secondary w-100" type="submit">
                                    <i class="bi bi-paperclip" aria-hidden="true"></i> File
                                </button>
                            </div>
                        </div>
                    </form>
                @elseif ($canManage)
                    <div class="erp-filter-note mt-3">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        Every document in the library is already filed here, or the library is empty.
                        <a href="{{ $libraryUrl }}">Upload one</a>.
                    </div>
                @endif
            </div>
        </section>

        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What has happened to it</h2>
                    <p class="erp-card-sub">Renewals, filings, attachments and the retirement — the history the audit trail keeps, read in the register's own words.</p>
                </div>
            </header>
            <div class="px-3 pb-3">
                @forelse ($record->events as $event)
                    <div class="erp-list-row erp-list-row-top">
                        <div class="erp-list-row-main">
                            <span class="erp-chip {{ $event->action === 'retired' ? 'erp-chip-outline' : 'erp-chip-soft' }}">
                                {{ $event->actionLabel() }}
                            </span>
                            <div>{{ $event->note }}</div>
                            @if (! empty($event->meta['note']))
                                <div class="erp-td-muted">“{{ $event->meta['note'] }}”</div>
                            @endif
                        </div>
                        <div class="erp-td-muted text-nowrap">
                            {{ $event->happened_on->format('d M Y') }}
                            @if ($event->actor)
                                <div>{{ $event->actor->name }}</div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="erp-td-muted mb-0">Nothing yet — the record was just created.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="erp-split-side">
        @if ($canManage && $config['validity'] && ! $record->isRetired())
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Renew it</h2>
                        <p class="erp-card-sub">The previous expiry stays in the history — the number that was true last year is still a fact.</p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('records.renew', $record) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="renew_expires_on">New expiry date <span class="text-danger">*</span></label>
                        <input class="form-control @error('expires_on') is-invalid @enderror" type="date"
                               name="expires_on" id="renew_expires_on" required>
                        @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="renewed_on">Renewed on</label>
                        <input class="form-control" type="date" name="renewed_on" id="renewed_on" value="{{ now()->toDateString() }}">
                    </div>
                    @if ($config['reference'])
                        <div class="mb-3">
                            <label class="form-label" for="renew_reference_no">New {{ strtolower($config['reference_label']) }}</label>
                            <input class="form-control" type="text" name="reference_no" id="renew_reference_no"
                                   maxlength="120" placeholder="Leave empty to keep {{ $record->reference_no ?: 'none' }}">
                        </div>
                    @endif
                    @if ($config['value'])
                        <div class="mb-3">
                            <label class="form-label" for="renew_value_amount">Renewal {{ strtolower($config['value_label']) }}</label>
                            <input class="form-control" type="number" step="0.01" min="0" name="value_amount" id="renew_value_amount">
                        </div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label" for="renew_note">Note</label>
                        <input class="form-control" type="text" name="note" id="renew_note" maxlength="255"
                               placeholder="Paid at the counter, receipt 4412">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Renew</button>
                </form>
            </section>
        @endif

        @if ($canManage && $config['due'] && ! $record->isRetired())
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Mark it done</h2>
                        <p class="erp-card-sub">
                            @if ($record->repeat_months)
                                The next due date is set from the deadline by {{ $registry->cadenceLabel($record->repeat_months) }}.
                            @else
                                This one does not repeat, so nothing will be due afterwards.
                            @endif
                        </p>
                    </div>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('records.complete', $record) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="completed_on">Done on</label>
                        <input class="form-control" type="date" name="completed_on" id="completed_on" value="{{ now()->toDateString() }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="complete_note">Note</label>
                        <input class="form-control" type="text" name="note" id="complete_note" maxlength="255"
                               placeholder="Return filed, acknowledgement in the vault">
                    </div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Record it</button>
                </form>
            </section>
        @endif

        @if ($canManage)
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Correct the record</h2>
                </header>
                <form class="p-3 pt-0" method="POST" action="{{ route('records.update', $record) }}">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <label class="form-label" for="edit_title">Title</label>
                        <input class="form-control" type="text" name="title" id="edit_title" value="{{ $record->title }}" maxlength="191" required>
                    </div>
                    @if ($config['reference'])
                        <div class="mb-3">
                            <label class="form-label" for="edit_reference_no">{{ $config['reference_label'] }}</label>
                            <input class="form-control" type="text" name="reference_no" id="edit_reference_no"
                                   value="{{ $record->reference_no }}" maxlength="120">
                        </div>
                    @endif
                    @if ($config['issuer'])
                        <div class="mb-3">
                            <label class="form-label" for="edit_issuer">{{ $config['issuer_label'] }}</label>
                            <input class="form-control" type="text" name="issuer" id="edit_issuer" value="{{ $record->issuer }}" maxlength="160">
                        </div>
                    @endif
                    @if ($config['party'])
                        <div class="mb-3">
                            <label class="form-label" for="edit_counterparty">{{ $config['party_label'] }}</label>
                            <input class="form-control" type="text" name="counterparty" id="edit_counterparty"
                                   value="{{ $record->counterparty }}" maxlength="160">
                        </div>
                    @endif
                    @if ($config['validity'])
                        <div class="mb-3">
                            <label class="form-label" for="edit_expires_on">Expires on</label>
                            <input class="form-control @error('expires_on') is-invalid @enderror" type="date"
                                   name="expires_on" id="edit_expires_on"
                                   value="{{ $record->expires_on?->toDateString() }}">
                            @error('expires_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                    @if ($config['due'])
                        <div class="mb-3">
                            <label class="form-label" for="edit_due_on">Next due</label>
                            <input class="form-control @error('due_on') is-invalid @enderror" type="date"
                                   name="due_on" id="edit_due_on" value="{{ $record->due_on?->toDateString() }}">
                            @error('due_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="edit_repeat_months">Comes round</label>
                            <select class="form-select" name="repeat_months" id="edit_repeat_months">
                                <option value="">Once only</option>
                                @foreach (\App\Domain\Business\RecordsRegistry::CADENCES as $months => $label)
                                    <option value="{{ $months }}" @selected((int) $record->repeat_months === (int) $months)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <div class="mb-3">
                        <label class="form-label" for="edit_notes">Notes</label>
                        <textarea class="form-control" name="notes" id="edit_notes" rows="3" maxlength="2000">{{ $record->notes }}</textarea>
                    </div>
                    <button class="btn btn-outline-secondary" type="submit">Save changes</button>
                </form>
            </section>

            @if (! $record->isRetired())
                <section class="erp-card mt-3">
                    <header class="erp-card-head">
                        <div>
                            <h2 class="erp-card-title">Retire it</h2>
                            <p class="erp-card-sub">Surrendered, replaced or torn up. The row stays; the working lists let it go.</p>
                        </div>
                    </header>
                    <form class="p-3 pt-0" method="POST" action="{{ route('records.retire', $record) }}"
                          data-confirm="Retire “{{ $record->title }}”? It leaves the working lists but stays on the register.">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="reason">Why</label>
                            <input class="form-control @error('reason') is-invalid @enderror" type="text" name="reason"
                                   id="reason" maxlength="255" required placeholder="Superseded by the 2027 licence">
                            @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-outline-danger" type="submit">
                            <i class="bi bi-archive" aria-hidden="true"></i> Retire
                        </button>
                    </form>
                </section>
            @endif
        @endif
    </div>
</div>

<x-ui.related-pages />
