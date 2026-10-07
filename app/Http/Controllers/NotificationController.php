<?php

namespace App\Http\Controllers;

use App\Domain\Notification\Notification;
use App\Domain\Notification\Services\NotificationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** In-app notification centre (Rule J) — truthful unread/read states. */
class NotificationController extends Controller
{
    public function __construct(protected NotificationCenter $center) {}

    public function index(Request $request): View
    {
        $notifications = Notification::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('notifications.index', [
            'notifications' => $notifications,
            'unread' => $this->center->unreadCount($request->user()),
        ]);
    }

    /** Header poll endpoint — counts come from real rows only. */
    public function poll(Request $request): JsonResponse
    {
        $user = $request->user();

        $latest = Notification::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'title', 'body', 'priority', 'action_url', 'read_at', 'created_at']);

        return response()->json([
            'ok' => true,
            'unread' => $this->center->unreadCount($user),
            'latest' => $latest,
            'poll_seconds' => (int) config('erp.notifications.poll_seconds', 60),
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $found = $this->center->markRead($request->user(), $id);

        abort_unless($found, 404, 'Notification not found.');

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $count = $this->center->markAllRead($request->user());

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'marked' => $count]);
        }

        return back()->with('status', "{$count} notifications marked as read.");
    }
}
