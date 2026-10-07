@extends('layouts.app')

@section('page_title', 'Approval request')

@section('content')
    @php($req = $requestModel)

    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $req->subject }}</h1>
            <p class="erp-page-sub">
                <span class="erp-status erp-status-{{ $req->status }}">{{ $req->status }}</span>
                @if($req->reference_no)<code>{{ $req->reference_no }}</code> · @endif
                {{ $req->definition?->name ?: $req->entity_type.' · '.$req->action }}
                · revision {{ $req->revision }} · workflow v{{ $req->workflow_version }}
            </p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('approvals.index') }}">Back to inbox</a>
    </div>

    @if ($selfBlocked)
        <div class="alert alert-warning">
            <i class="bi bi-person-slash me-1" aria-hidden="true"></i>
            This workflow blocks self-approval — you submitted this request, so you cannot approve it yourself.
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Request</h2>
                    @if ($req->amount !== null)
                        <span class="erp-amount erp-amount-lg">৳{{ number_format((float) $req->amount, 2) }} {{ $req->currency }}</span>
                    @endif
                </header>
                <dl class="erp-dl">
                    <dt>Entity</dt><dd><code>{{ $req->entity_type }}#{{ $req->entity_id }}</code></dd>
                    <dt>Action</dt><dd>{{ $req->action }}</dd>
                    <dt>Submitted by</dt>
                    <dd>{{ $req->submitter?->name ?: 'system' }} @if($req->submitted_at)· {{ $req->submitted_at->format('d M Y, H:i') }}@endif</dd>
                    <dt>Current level</dt><dd>{{ $req->current_level }}</dd>
                    <dt>Due</dt>
                    <dd>
                        @if($req->due_at)
                            {{ $req->due_at->format('d M Y, H:i') }}
                            @if($req->due_at->isPast() && $req->status === 'pending')
                                <span class="erp-chip erp-chip-warn">overdue</span>
                            @endif
                        @else
                            —
                        @endif
                    </dd>
                    @if($req->decided_at)
                        <dt>Decided</dt><dd>{{ $req->decided_at->format('d M Y, H:i') }}</dd>
                    @endif
                    <dt>Snapshot hash</dt>
                    <dd><code class="erp-hash" title="{{ $req->snapshot_hash }}">{{ \Illuminate\Support\Str::limit($req->snapshot_hash, 24, '…') }}</code></dd>
                </dl>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Approval steps</h2>
                    <span class="erp-chip erp-chip-soft">{{ $req->frozenMode() }} · {{ $req->definition?->name ?: 'definition removed' }}</span>
                </header>
                <ol class="erp-timeline">
                    @forelse($req->steps->sortBy('level') as $step)
                        <li class="erp-timeline-item erp-timeline-{{ $step->status }}">
                            <span class="erp-timeline-marker" aria-hidden="true">
                                <i class="bi {{ ['pending' => 'bi-hourglass-split', 'approved' => 'bi-check2-circle', 'rejected' => 'bi-x-circle', 'returned' => 'bi-arrow-counterclockwise', 'cancelled' => 'bi-slash-circle', 'skipped' => 'bi-dash-circle'][$step->status] ?? 'bi-circle' }}"></i>
                            </span>
                            <div class="erp-timeline-body">
                                <strong>Level {{ $step->level }}</strong>
                                <span class="erp-status erp-status-{{ $step->status }}">{{ $step->status }}</span>
                                @if($step->is_required)<span class="erp-chip erp-chip-soft">required</span>@else<span class="erp-chip erp-chip-soft">optional</span>@endif
                                <div class="small text-body-secondary">
                                    Approver:
                                    @if($step->approver_user_id)
                                        {{ $step->approverUser?->name ?: 'user #'.$step->approver_user_id }}
                                    @elseif($step->approver_role_id)
                                        role · {{ $step->approverRole?->name ?: 'role #'.$step->approver_role_id }}
                                    @else
                                        —
                                    @endif
                                    @if($step->acted_by)
                                        · acted by <strong>{{ $step->actedByUser?->name ?: 'user #'.$step->acted_by }}</strong>
                                        {{ $step->acted_at?->format('d M, H:i') }}
                                    @elseif($step->due_at)
                                        · due {{ $step->due_at->format('d M, H:i') }}
                                    @endif
                                    @if($step->escalated_at)
                                        · <span class="erp-chip erp-chip-warn">escalated {{ $step->escalated_at->format('d M, H:i') }}</span>
                                    @endif
                                </div>
                            </div>
                        </li>
                    @empty
                        <li class="text-body-secondary">No steps were generated for this request.</li>
                    @endforelse
                </ol>
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Action history</h2>
                    <span class="erp-chip erp-chip-soft">{{ $req->actions->count() }}</span>
                </header>
                @forelse($req->actions as $action)
                    <div class="erp-list-row erp-list-row-top">
                        <div>
                            <span class="erp-chip erp-chip-soft">{{ $action->action }}</span>
                            @if($action->level !== null)<span class="small text-body-secondary">level {{ $action->level }}</span>@endif
                            <div class="small">{{ $action->user?->name ?: 'system' }} · {{ $action->created_at?->format('d M Y, H:i') }}</div>
                        </div>
                        @if($action->comment)
                            <div class="erp-comment">{{ $action->comment }}</div>
                        @endif
                    </div>
                @empty
                    <p class="text-body-secondary">No actions recorded yet.</p>
                @endforelse
            </section>

            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Submission snapshot</h2>
                    <span class="erp-chip erp-chip-soft">frozen at submit · sha256 verified</span>
                </header>
                <details>
                    <summary class="erp-summary">Show snapshot payload (already redacted)</summary>
                    <pre class="erp-pre">{{ json_encode($req->snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </details>
            </section>
        </div>

        <div class="col-lg-5">
            <section class="erp-card">
                <header class="erp-card-head"><h2 class="erp-card-title">Your decision</h2></header>

                @if ($req->status !== 'pending')
                    <p class="text-body-secondary mb-0">
                        This request is <strong>{{ $req->status }}</strong> — no further actions are possible.
                    </p>
                @elseif (! $canAct && ! $canReject && ! $canCancel)
                    <p class="text-body-secondary mb-0">
                        You have no actionable step on this request (role, level and branch rules are checked server-side).
                    </p>
                @endif

                @if ($canAct)
                    <form method="POST" action="{{ route('approvals.approve', $req) }}" class="mb-3">
                        @csrf
                        <label class="form-label" for="approve_comment">Comment (optional)</label>
                        <textarea class="form-control mb-2" id="approve_comment" name="comment" rows="2" maxlength="2000"></textarea>
                        @error('comment')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                        <button class="btn btn-success w-100" type="submit">
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve
                        </button>
                    </form>
                @endif

                @if ($canReject)
                    <form method="POST" action="{{ route('approvals.reject', $req) }}" class="mb-3"
                          data-confirm="Reject this request? A comment explaining why is required.">
                        @csrf
                        <label class="form-label" for="reject_comment">Reason for rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control mb-2" id="reject_comment" name="comment" rows="2" maxlength="2000" required></textarea>
                        <button class="btn btn-outline-danger w-100" type="submit">
                            <i class="bi bi-x-circle" aria-hidden="true"></i> Reject
                        </button>
                    </form>

                    <form method="POST" action="{{ route('approvals.return', $req) }}" class="mb-3">
                        @csrf
                        <label class="form-label" for="return_comment">Return for correction — instructions <span class="text-danger">*</span></label>
                        <textarea class="form-control mb-2" id="return_comment" name="comment" rows="2" maxlength="2000" required></textarea>
                        <button class="btn btn-outline-warning w-100" type="submit">
                            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Return for correction
                        </button>
                    </form>
                @endif

                @if ($canComment)
                    <form method="POST" action="{{ route('approvals.comment', $req) }}" class="mt-3">
                        @csrf
                        <label class="form-label" for="comment_comment">Add a comment</label>
                        <textarea class="form-control mb-2" id="comment_comment" name="comment" rows="2" maxlength="2000" required></textarea>
                        <button class="btn btn-outline-secondary" type="submit">
                            <i class="bi bi-chat-left-text" aria-hidden="true"></i> Post comment
                        </button>
                    </form>
                @endif

                @if ($canCancel)
                    <form method="POST" action="{{ route('approvals.cancel', $req) }}" class="mt-3"
                          data-confirm="Cancel this request? It will never be decided.">
                        @csrf
                        <button class="btn btn-link text-danger p-0" type="submit">
                            <i class="bi bi-slash-circle" aria-hidden="true"></i> Cancel this request
                        </button>
                    </form>
                @endif
            </section>

            @if ($req->definition && $req->definition->conditions->isNotEmpty())
                <section class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">Matched conditions</h2></header>
                    @foreach($req->definition->conditions as $condition)
                        <div class="erp-list-row">
                            <code class="small">{{ $condition->subject }}</code>
                            <span class="erp-chip erp-chip-soft">{{ $condition->operator }}</span>
                            <span class="small text-body-secondary">
                                {{ $condition->value_string ?? ($condition->value_min.($condition->value_max !== null ? ' – '.$condition->value_max : '')) }}
                                @if($condition->condition_group > 0)· group {{ $condition->condition_group }}@endif
                            </span>
                        </div>
                    @endforeach
                </section>
            @endif
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
