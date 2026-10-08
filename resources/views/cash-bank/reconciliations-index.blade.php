@extends('layouts.app')

@section('page_title', $instrument === 'wallet' ? 'Mobile reconciliation' : 'Bank reconciliation')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · {{ $instrument === 'wallet' ? 'Mobile banking' : 'Bank accounts' }}"
        title="{{ $instrument === 'wallet' ? 'Reconciling the wallets' : 'Reconciling the bank accounts' }}"
        subtitle="A bank book that has never been checked against the bank is a set of intentions. Each row here is one account: what it holds in the books, how much of the institution's own statement has been loaded, and whether the last reconciliation added up."
        :pin="true">
        <x-slot:actions>
            @if ($perm('cash.view'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                    <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
                </a>
            @endif
            @if ($perm('bank.accounts'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">
                    <i class="bi bi-bank" aria-hidden="true"></i> Money accounts
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($instrument === 'wallet' && ! $walletApiConnected)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-plug" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">No provider API is connected — and this screen will not pretend one is</strong>
                Reconcile from the statement the provider app exports as CSV. The wallet's balance stays the ledger's figure; this desk is what proves it against what bKash, Nagad, Rocket or Upay say.
            </div>
        </div>
    @endif

    @if ($rows === [])
        <div class="erp-card p-3">
            <x-ui.empty
                :title="$instrument === 'wallet' ? 'No mobile wallet has been opened yet' : 'No bank account has been declared yet'"
                icon="bi-bank"
                :text="$instrument === 'wallet'
                    ? 'A wallet is declared like a bank account: a real ledger account with a provider on it.'
                    : 'A bank account is declared on the money accounts screen, and declaring it creates the ledger account its money is posted to.'"
                action="Money accounts"
                :href="route('cash-bank.accounts')" />
        </div>
    @else
        <x-ui.table-shell :title="$instrument === 'wallet' ? 'Wallets to reconcile' : 'Bank accounts to reconcile'" :count="count($rows).' account(s)'">
            <thead>
                <tr>
                    <th>Account</th>
                    <th class="erp-th-num">Book balance</th>
                    <th>Statement lines</th>
                    <th class="erp-th-num">Without a counterpart</th>
                    <th>Last reconciliation</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <span class="erp-cell-strong">{{ $row['account']->name }}</span>
                            <span class="d-block erp-td-muted">{{ $row['label'] }} · {{ $row['account']->code }}</span>
                        </td>
                        <td class="erp-td-num"><span class="erp-cell-strong">{{ number_format((float) $row['balance'], 2) }}</span></td>
                        <td class="erp-td-muted">
                            {{ number_format($row['lines']) }} line(s)
                            @if ($row['from'])
                                <span class="d-block">{{ $row['from'] }} → {{ $row['to'] }}</span>
                            @else
                                <span class="d-block">Nothing imported yet</span>
                            @endif
                        </td>
                        <td class="erp-td-num">
                            @if ($row['unmatched'] > 0)
                                <span class="erp-money-out">{{ number_format($row['unmatched']) }}</span>
                            @else
                                <span class="erp-td-muted">—</span>
                            @endif
                        </td>
                        <td>
                            @if ($row['last'] === null)
                                <span class="erp-td-muted">Never reconciled</span>
                            @else
                                <x-ui.status :value="$row['last']->status" />
                                <span class="d-block erp-td-muted mt-1">
                                    {{ $row['last']->label() }} · unexplained {{ number_format((float) $row['last']->difference, 2) }}
                                </span>
                            @endif
                        </td>
                        <td class="erp-td-actions">
                            @php($prefix = $instrument === 'wallet' ? 'cash-bank.wallets' : 'cash-bank')
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route($prefix.'.statement', ['account' => $row['account']->id]) }}">
                                <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Statement
                            </a>
                            <a class="btn btn-sm btn-primary" href="{{ route($prefix.'.reconcile', ['account' => $row['account']->id]) }}">
                                <i class="bi bi-shield-check" aria-hidden="true"></i> Reconcile
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @endif
@endsection
