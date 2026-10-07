@extends('layouts.app')

@section('page_title', $customer->name)

@section('content')
    <x-ui.page-header
        eyebrow="Customer · {{ $customer->code }}"
        :title="$customer->name"
        :meta="array_filter([
            $customer->type === 'business' ? 'Business' : 'Individual',
            $customer->phone,
            $customer->district?->name,
            $customer->group?->name ? 'Group: '.$customer->group->name : null,
        ])"
        :pin="true">
        <x-slot:actions>
            @if ($perm('accounting.ledger.view'))
                <a class="btn btn-outline-secondary" href="{{ route('customers.ledger', $customer) }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> Ledger
                </a>
                <a class="btn btn-outline-secondary" href="{{ route('customers.statement', $customer) }}" target="_blank" rel="noopener">
                    <i class="bi bi-printer" aria-hidden="true"></i> Statement
                </a>
            @endif
            @if ($perm('customers.edit'))
                <a class="btn btn-primary" href="{{ route('customers.edit', $customer) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> Edit
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($customer->is_blacklisted)
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>
            <div>
                <strong>Blacklisted — new documents are refused.</strong>
                Reason: {{ $customer->blacklist_reason ?: 'not recorded' }}.
                @if ($perm('customers.blacklist'))
                    Restore the customer below once the dispute is settled.
                @endif
            </div>
        </div>
    @endif

    @if ($receivable && (float) $customer->credit_limit > 0 && $receivable['due'] > (float) $customer->credit_limit)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
            <div>
                <strong>Exposure is above the approved credit limit.</strong>
                Due ৳ {{ number_format($receivable['due'], 2) }} against a limit of ৳ {{ number_format((float) $customer->credit_limit, 2) }}
                — over by ৳ {{ number_format($receivable['due'] - (float) $customer->credit_limit, 2) }}. Collect, or raise the limit with a reason.
            </div>
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Lifetime invoiced" value="৳ {{ number_format($receivable['invoiced'] ?? 0, 2) }}" icon="bi-receipt" hint="Issued + paid invoices" />
        <x-ui.kpi label="Received" value="৳ {{ number_format($receivable['paid'] ?? 0, 2) }}" icon="bi-cash-coin" hint="Posted receipts" />
        <x-ui.kpi label="Outstanding" value="৳ {{ number_format($receivable['due'] ?? 0, 2) }}" icon="bi-hourglass-split" hint="{{ $openInvoices->count() }} open invoice{{ $openInvoices->count() === 1 ? '' : 's' }}" />
        <x-ui.kpi label="Overdue" value="৳ {{ number_format($receivable['overdue'] ?? 0, 2) }}" icon="bi-alarm" hint="{{ $receivable['oldest_due'] ? 'Oldest due '.$receivable['oldest_due'] : 'Nothing overdue' }}" />
    </div>

    <div class="erp-split">
        <div class="erp-split-main">
            <section class="erp-card mb-3">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Recent documents</h2>
                    @if ($perm('sales.invoices.view'))
                        <div class="erp-card-actions">
                            <a class="btn btn-sm btn-light" href="{{ route('sales.invoices.index', ['q' => $customer->code]) }}">All invoices</a>
                        </div>
                    @endif
                </div>

                @if ($documents->isEmpty())
                    <x-ui.empty
                        icon="bi-receipt"
                        title="No invoices yet"
                        text="Documents raised against this customer will appear here with their payment state." />
                @else
                    <div class="table-responsive">
                        <table class="table erp-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th class="erp-th-num">Total</th>
                                    <th class="erp-th-num">Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($documents as $document)
                                    <tr>
                                        <td><span class="erp-row-link">{{ $document['no'] }}</span></td>
                                        <td class="erp-td-muted">{{ $document['date'] }}</td>
                                        <td><x-ui.status :value="$document['status']" /></td>
                                        <td class="erp-td-num">৳ {{ number_format($document['total'], 2) }}</td>
                                        <td class="erp-td-num {{ $document['due'] > 0 ? 'erp-amount-warn' : 'erp-td-muted' }}">
                                            ৳ {{ number_format($document['due'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="erp-card mb-3" id="ledger-derived">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">
                        Open invoices
                        <span class="erp-chip erp-chip-outline">{{ $openInvoices->count() }}</span>
                    </h2>
                    <div class="erp-card-actions">
                        @if ($perm('accounting.ledger.view'))
                            <a class="btn btn-sm btn-light" href="{{ route('customers.ledger', $customer) }}">Full ledger</a>
                        @endif
                    </div>
                </div>

                @if ($openInvoices->isEmpty())
                    <x-ui.empty icon="bi-check2-circle" title="Nothing outstanding" text="Every issued invoice has been settled in full." />
                @else
                    <div class="table-responsive">
                        <table class="table erp-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Due date</th>
                                    <th>Ageing</th>
                                    <th class="erp-th-num">Total</th>
                                    <th class="erp-th-num">Paid</th>
                                    <th class="erp-th-num">Due</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($openInvoices as $invoice)
                                    <tr>
                                        <td>{{ $invoice->invoice_no }}</td>
                                        <td class="erp-td-muted">{{ $invoice->due_date ?? '—' }}</td>
                                        <td>
                                            <span class="erp-status erp-status-{{ $invoice->bucket === 'current' ? 'issued' : 'pending' }}">
                                                {{ str_replace('_', '-', $invoice->bucket) }}
                                            </span>
                                        </td>
                                        <td class="erp-td-num">৳ {{ number_format((float) $invoice->grand_total, 2) }}</td>
                                        <td class="erp-td-num erp-td-muted">৳ {{ number_format((float) $invoice->paid_amount, 2) }}</td>
                                        <td class="erp-td-num erp-amount">{{ number_format($invoice->due, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="erp-card mb-3">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Addresses</h2>
                </div>

                @if ($addresses->isEmpty())
                    <x-ui.empty icon="bi-geo-alt" title="No delivery address on file" text="Add the address your courier actually delivers to." />
                @else
                    <ul class="erp-list">
                        @foreach ($addresses as $address)
                            <li class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <span class="erp-chip erp-chip-soft">{{ $address->label }}</span>
                                    @if ($address->is_default)<span class="erp-chip erp-chip-ok">default</span>@endif
                                    <span>{{ $address->oneLine() }}</span>
                                    @if ($address->contact_phone)
                                        <span class="erp-td-muted">· {{ $address->contact_name }} {{ $address->contact_phone }}</span>
                                    @endif
                                </div>
                                @if ($perm('customers.edit'))
                                    <form method="POST" action="{{ route('customers.addresses.destroy', [$customer, $address]) }}"
                                          data-confirm="Remove this address?">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light" type="submit">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($perm('customers.edit'))
                    <form class="erp-inline-form mt-3" method="POST" action="{{ route('customers.addresses.store', $customer) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-sm-3">
                                <label class="form-label" for="addr_label">Label</label>
                                <select class="form-select" id="addr_label" name="label">
                                    @foreach (['Delivery', 'Billing', 'Warehouse', 'Other'] as $label)
                                        <option value="{{ $label }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-5">
                                <label class="form-label" for="addr_line1">Address</label>
                                <input class="form-control" id="addr_line1" name="address_line1" required maxlength="191">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="addr_district">District</label>
                                <select class="form-select" id="addr_district" name="district_id">
                                    <option value="">Not set</option>
                                    @foreach ($districts as $district)
                                        <option value="{{ $district->id }}">{{ $district->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="addr_contact">Contact name</label>
                                <input class="form-control" id="addr_contact" name="contact_name" maxlength="128">
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label" for="addr_phone">Contact phone</label>
                                <input class="form-control" id="addr_phone" name="contact_phone" maxlength="32">
                            </div>
                            <div class="col-sm-4 d-flex align-items-end gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="addr_default" name="is_default" value="1">
                                    <label class="form-check-label" for="addr_default">Default</label>
                                </div>
                                <button class="btn btn-outline-secondary" type="submit">Add address</button>
                            </div>
                        </div>
                    </form>
                @endif
            </section>

            <section class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Contacts</h2>
                </div>

                @if ($contacts->isEmpty())
                    <x-ui.empty icon="bi-person-lines-fill" title="No named contacts" text="Record the people you actually deal with — procurement, accounts, the shop manager." />
                @else
                    <ul class="erp-list">
                        @foreach ($contacts as $contact)
                            <li class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <strong>{{ $contact->name }}</strong>
                                    @if ($contact->is_primary)<span class="erp-chip erp-chip-ok">primary</span>@endif
                                    <span class="erp-td-muted">
                                        {{ $contact->designation ? $contact->designation.' · ' : '' }}{{ $contact->phone }}
                                        @if ($contact->email) · {{ $contact->email }}@endif
                                    </span>
                                </div>
                                @if ($perm('customers.edit'))
                                    <form method="POST" action="{{ route('customers.contacts.destroy', [$customer, $contact]) }}"
                                          data-confirm="Remove this contact?">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light" type="submit">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($perm('customers.edit'))
                    <form class="erp-inline-form mt-3" method="POST" action="{{ route('customers.contacts.store', $customer) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-sm-3">
                                <label class="form-label" for="c_name">Name</label>
                                <input class="form-control" id="c_name" name="name" required maxlength="128">
                            </div>
                            <div class="col-sm-3">
                                <label class="form-label" for="c_desig">Designation</label>
                                <input class="form-control" id="c_desig" name="designation" maxlength="96">
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label" for="c_phone">Phone</label>
                                <input class="form-control" id="c_phone" name="phone" maxlength="32">
                            </div>
                            <div class="col-sm-2">
                                <label class="form-label" for="c_email">E-mail</label>
                                <input class="form-control" id="c_email" name="email" type="email" maxlength="191">
                            </div>
                            <div class="col-sm-2 d-flex align-items-end gap-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="c_primary" name="is_primary" value="1">
                                    <label class="form-check-label" for="c_primary">Primary</label>
                                </div>
                                <button class="btn btn-outline-secondary" type="submit">Add</button>
                            </div>
                        </div>
                    </form>
                @endif
            </section>
        </div>

        <aside class="erp-split-side">
            <section class="erp-card mb-3">
                <h2 class="erp-card-title mb-3">Credit control</h2>

                <dl class="erp-dl erp-dl-tight">
                    <dt>Credit limit</dt>
                    <dd>৳ {{ number_format((float) $customer->credit_limit, 2) }}</dd>
                    <dt>Credit days</dt>
                    <dd>{{ $customer->credit_days }} day{{ $customer->credit_days === 1 ? '' : 's' }}</dd>
                    <dt>Exposure</dt>
                    <dd>৳ {{ number_format($receivable['due'] ?? 0, 2) }}</dd>
                    <dt>Segment</dt>
                    <dd>{{ $customer->segment ? ucfirst(str_replace('_', ' ', $customer->segment)) : 'Not set' }}</dd>
                    <dt>Loyalty points</dt>
                    <dd>{{ number_format($customer->loyalty_points) }}</dd>
                </dl>

                @if ($perm('customers.credit_limit'))
                    <form class="mt-3" method="POST" action="{{ route('customers.credit-limit', $customer) }}">
                        @csrf @method('PUT')
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label" for="limit">New limit (৳)</label>
                                <input class="form-control" id="limit" name="limit" inputmode="decimal"
                                       value="{{ number_format((float) $customer->credit_limit, 2, '.', '') }}" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="credit_days">Credit days</label>
                                <input class="form-control" id="credit_days" name="credit_days" inputmode="numeric"
                                       value="{{ $customer->credit_days }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="limit_reason">Reason</label>
                                <input class="form-control" id="limit_reason" name="reason" maxlength="500"
                                       placeholder="e.g. 6 months of on-time settlement">
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary w-100 mt-2" type="submit">Record new limit</button>
                    </form>
                @endif

                @if ($creditHistory->isNotEmpty())
                    <div class="erp-timeline mt-3">
                        @foreach ($creditHistory as $row)
                            <div class="erp-timeline-item">
                                <span class="erp-timeline-marker" aria-hidden="true"></span>
                                <div class="erp-timeline-body">
                                    <strong>৳ {{ number_format((float) $row->old_limit, 2) }} → ৳ {{ number_format((float) $row->new_limit, 2) }}</strong>
                                    <p class="mb-0 small">
                                        {{ $row->reason ?: 'No reason recorded' }} ·
                                        {{ \Illuminate\Support\Carbon::parse($row->created_at)->format('d M Y') }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($perm('customers.blacklist'))
                <section class="erp-card mb-3">
                    <h2 class="erp-card-title mb-3">{{ $customer->is_blacklisted ? 'Restore customer' : 'Blacklist' }}</h2>

                    <form method="POST" action="{{ route('customers.blacklist', $customer) }}"
                          data-confirm="{{ $customer->is_blacklisted ? 'Restore this customer?' : 'Blacklist this customer? New documents will be refused.' }}">
                        @csrf
                        <input type="hidden" name="blacklisted" value="{{ $customer->is_blacklisted ? 0 : 1 }}">
                        @unless ($customer->is_blacklisted)
                            <label class="form-label" for="blacklist_reason">Reason (required)</label>
                            <textarea class="form-control" id="blacklist_reason" name="reason" rows="2" maxlength="500" required></textarea>
                        @endunless
                        <button class="btn {{ $customer->is_blacklisted ? 'btn-outline-secondary' : 'btn-danger' }} w-100 mt-2" type="submit">
                            {{ $customer->is_blacklisted ? 'Restore customer' : 'Blacklist customer' }}
                        </button>
                    </form>
                </section>
            @endif

            <section class="erp-card mb-3">
                <h2 class="erp-card-title mb-3">
                    Relationship health
                    @if ($nps['score'] !== null)
                        <span class="erp-chip {{ $nps['score'] >= 0 ? 'erp-chip-ok' : 'erp-chip-warn' }}">NPS {{ $nps['score'] }}</span>
                    @endif
                </h2>
                <p class="erp-td-muted small mb-3">
                    @if ($nps['samples'] === 0)
                        No feedback recorded yet — {{ $nps['samples'] }} samples.
                    @else
                        NPS from {{ $nps['samples'] }} sample{{ $nps['samples'] === 1 ? '' : 's' }}.
                    @endif
                </p>

                @if ($perm('customers.feedback'))
                    <form method="POST" action="{{ route('customers.feedback.store', $customer) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-4">
                                <label class="form-label" for="score">Score</label>
                                <input class="form-control" id="score" name="score" inputmode="numeric" min="0" max="10" value="9" required>
                            </div>
                            <div class="col-8">
                                <label class="form-label" for="channel">Channel</label>
                                <select class="form-select" id="channel" name="channel">
                                    @foreach (\App\Domain\Customers\Models\CustomerFeedback::CHANNELS as $channel)
                                        <option value="{{ $channel }}">{{ ucfirst(str_replace('_', ' ', $channel)) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="comment">Comment</label>
                                <input class="form-control" id="comment" name="comment" maxlength="1000">
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary w-100 mt-2" type="submit">Record feedback</button>
                    </form>
                @endif

                @if ($feedback->isNotEmpty())
                    <div class="erp-timeline mt-3">
                        @foreach ($feedback as $sample)
                            <div class="erp-timeline-item">
                                <span class="erp-timeline-marker erp-timeline-{{ $sample->sentiment() }}" aria-hidden="true"></span>
                                <div class="erp-timeline-body">
                                    <strong>{{ $sample->score }}/10 · {{ $sample->sentiment() }}</strong>
                                    <p class="mb-0 small">
                                        {{ $sample->comment ?: 'No comment' }} ·
                                        {{ $sample->created_at->format('d M Y') }}
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="erp-card mb-3">
                <h2 class="erp-card-title mb-3">Referrals</h2>


                @if ($referrals->isEmpty())
                    <p class="erp-td-muted small mb-0">This customer has not referred anyone yet.</p>
                @else
                    <ul class="erp-list">
                        @foreach ($referrals as $referral)
                            <li class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <strong>{{ $referral->referred_name }}</strong>
                                    <span class="erp-status erp-status-{{ $referral->status }}">{{ $referral->status }}</span>
                                    @if ((float) $referral->reward_amount > 0)
                                        <span class="erp-td-muted">৳ {{ number_format((float) $referral->reward_amount, 2) }}</span>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($perm('customers.referrals'))
                    <form class="mt-3" method="POST" action="{{ route('customers.referrals.store', $customer) }}">
                        @csrf
                        <div class="row g-2">
                            <div class="col-7">
                                <label class="form-label" for="referred_name">Referred name</label>
                                <input class="form-control" id="referred_name" name="referred_name" required maxlength="191">
                            </div>
                            <div class="col-5">
                                <label class="form-label" for="referred_phone">Phone</label>
                                <input class="form-control" id="referred_phone" name="referred_phone" maxlength="32">
                            </div>
                            <div class="col-7">
                                <label class="form-label" for="referral_status">Status</label>
                                <select class="form-select" id="referral_status" name="status">
                                    @foreach (\App\Domain\Customers\Models\CustomerReferral::STATUSES as $status)
                                        <option value="{{ $status }}">{{ ucfirst($status) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-5">
                                <label class="form-label" for="reward">Reward (৳)</label>
                                <input class="form-control" id="reward" name="reward_amount" inputmode="decimal" value="0">
                            </div>
                        </div>
                        <button class="btn btn-outline-secondary w-100 mt-2" type="submit">Log referral</button>
                    </form>
                @endif
            </section>

            <section class="erp-card">
                <h2 class="erp-card-title mb-3">Wishlist</h2>

                @if ($wishlist->isEmpty())
                    <p class="erp-td-muted small mb-0">Nothing on the watch-list. Add products the customer asked about.</p>
                @else
                    <ul class="erp-list">
                        @foreach ($wishlist as $wish)
                            <li class="erp-list-row">
                                <div class="erp-list-row-main">
                                    <span>{{ $wish->product?->name }}</span>
                                    <span class="erp-td-muted">{{ $wish->product?->sku }}</span>
                                    @if ($wish->note)<span class="erp-td-muted">· {{ $wish->note }}</span>@endif
                                </div>
                                @if ($perm('customers.edit'))
                                    <form method="POST" action="{{ route('customers.wishlist.destroy', [$customer, $wish]) }}">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-light" type="submit">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($perm('customers.edit'))
                    <form class="mt-3" method="POST" action="{{ route('customers.wishlist.store', $customer) }}">
                        @csrf
                        <label class="form-label" for="product_id">Add product</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="product_id" name="product_id" required>
                                <option value="">Choose…</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->sku }})</option>
                                @endforeach
                            </select>
                            <button class="btn btn-outline-secondary" type="submit">Add</button>
                        </div>
                    </form>
                @endif
            </section>
        </aside>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
