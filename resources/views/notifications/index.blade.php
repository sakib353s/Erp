@extends('layouts.app')

@section('page_title', 'Notifications')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Notifications</h1>
            <p class="erp-page-sub">
                {{ $unread }} unread · in-app channel is always on; external channels stay disabled until a real provider is configured.
            </p>
        </div>
        <form method="POST" action="{{ route('notifications.read_all') }}">
            @csrf
            <button class="btn btn-outline-secondary" type="submit" {{ $unread === 0 ? 'disabled' : '' }}>
                <i class="bi bi-check2-all" aria-hidden="true"></i> Mark all read
            </button>
        </form>
    </div>

    <div class="erp-card">
        @forelse($notifications as $notification)
            <div class="erp-notif-row {{ $notification->read_at === null ? 'unread' : '' }}">
                <div class="erp-notif-priority erp-notif-priority-{{ $notification->priority }}" aria-hidden="true"></div>
                <div class="erp-notif-body">
                    <div class="d-flex justify-content-between gap-2">
                        <strong>
                            @if($notification->action_url)
                                <a class="text-decoration-none" href="{{ $notification->action_url }}">{{ $notification->title }}</a>
                            @else
                                {{ $notification->title }}
                            @endif
                        </strong>
                        <span class="small text-body-secondary text-nowrap">{{ $notification->created_at?->diffForHumans() }}</span>
                    </div>
                    @if($notification->body)
                        <p class="mb-1 text-body-secondary">{{ $notification->body }}</p>
                    @endif
                    <div class="d-flex align-items-center gap-2">
                        <span class="erp-chip erp-chip-soft">{{ $notification->event_type }}</span>
                        <span class="erp-chip erp-chip-soft">{{ $notification->priority }}</span>
                        @if($notification->read_at === null)
                            <form method="POST" action="{{ route('notifications.read', ['id' => $notification->id]) }}">
                                @csrf
                                <button class="btn btn-link btn-sm p-0" type="submit">Mark read</button>
                            </form>
                        @else
                            <span class="small text-body-secondary">read {{ $notification->read_at?->diffForHumans() }}</span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="erp-empty p-4">
                <i class="bi bi-bell-slash" aria-hidden="true"></i>
                <p>No notifications yet.</p>
                <small>You'll be notified here when approvals, security events or system tasks need your attention.</small>
            </div>
        @endforelse
    </div>

    <div class="mt-3">{{ $notifications->links() }}</div>
@endsection
