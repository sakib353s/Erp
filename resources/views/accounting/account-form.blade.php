@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'Add Account' : 'Edit Account')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'Add Account' : 'Edit Account: '.$account->code }}</h1>
            <p class="erp-page-sub">Group accounts cannot receive postings. System accounts and accounts with history have edit restrictions.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('accounting.coa') }}">Back to COA</a>
    </div>

    <div class="erp-card" style="max-width: 720px">
        <form method="POST"
              action="{{ $mode === 'create' ? route('accounting.accounts.store') : route('accounting.accounts.update', $account) }}">
            @csrf
            @if ($mode === 'edit') @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code', $account->code) }}"
                           {{ $mode === 'edit' && $account->journalLines()->exists() ? 'readonly' : '' }}
                           required maxlength="32">
                    @error('code') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name', $account->name) }}" required maxlength="191">
                    @error('name') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type" {{ $mode === 'edit' && $account->journalLines()->exists() ? 'disabled' : '' }}>
                        @foreach ($types as $type)
                            <option value="{{ $type }}" @selected(old('type', $account->type) === $type)>{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    @if ($mode === 'edit' && $account->journalLines()->exists())
                        <input type="hidden" name="type" value="{{ $account->type }}">
                    @endif
                    @error('type') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="account_group_id">Account group</label>
                    <select class="form-select" id="account_group_id" name="account_group_id">
                        <option value="">— none —</option>
                        @foreach ($groups as $group)
                            <option value="{{ $group->id }}" @selected((int) old('account_group_id', $account->account_group_id) === $group->id)>
                                {{ $group->code }} · {{ $group->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('account_group_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="parent_id">Parent (group only)</label>
                    <select class="form-select" id="parent_id" name="parent_id">
                        <option value="">— top level —</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}" @selected((int) old('parent_id', $account->parent_id) === $parent->id)>
                                {{ $parent->code }} · {{ $parent->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('parent_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" value="{{ old('description', $account->description) }}" maxlength="500">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="currency">Currency</label>
                    <input class="form-control" id="currency" name="currency" value="{{ old('currency', $account->currency ?? 'BDT') }}" maxlength="3">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_group" value="1" id="is_group"
                               @checked(old('is_group', $account->is_group))>
                        <label class="form-check-label" for="is_group">Group account (header — no direct postings)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active"
                               @checked(old('is_active', $account->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_control_account" value="1" id="is_control_account"
                               @checked(old('is_control_account', $account->is_control_account))>
                        <label class="form-check-label" for="is_control_account">Control account (AR/AP)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_cash" value="1" id="is_cash"
                               @checked(old('is_cash', $account->is_cash))>
                        <label class="form-check-label" for="is_cash">Cash account</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_bank" value="1" id="is_bank"
                               @checked(old('is_bank', $account->is_bank))>
                        <label class="form-check-label" for="is_bank">Bank account</label>
                    </div>
                </div>

                @error('account') <div class="text-danger small">{{ $message }}</div> @enderror
                @error('lines') <div class="text-danger small">{{ $message }}</div> @enderror

                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-primary" type="submit">{{ $mode === 'create' ? 'Create account' : 'Save changes' }}</button>
                    <a class="btn btn-outline-secondary" href="{{ route('accounting.coa') }}">Cancel</a>
                </div>
            </div>
        </form>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
