<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the mobile app's notifications repository. Field names in the JSON
 * responses (body/read/createdAt, camelCase) are chosen to match exactly
 * what NotificationsScreen.js's normalizeNotification() already reads —
 * see that function's fallback chain — even though the database columns
 * themselves are message/is_read/sent_at.
 */
class NotificationApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('account_id', $request->user()->account_id)
            ->orderByDesc('sent_at')
            ->take(50)
            ->get()
            ->map(fn ($n) => $this->payload($n));

        return response()->json(['notifications' => $notifications->values()]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('account_id', $request->user()->account_id)
            ->where('is_read', false)
            ->count();

        return response()->json(['unreadCount' => $count]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->account_id === $request->user()->account_id, 404);

        $notification->update(['is_read' => true, 'read_at' => now()]);

        return response()->json(['ok' => true, 'notification' => $this->payload($notification)]);
    }

    private function payload(Notification $n): array
    {
        return [
            'id' => (string) $n->notification_id,
            'title' => $n->title,
            'body' => $n->message,
            'category' => $n->notification_type,
            'read' => (bool) $n->is_read,
            'createdAt' => optional($n->sent_at)->toIso8601String(),
        ];
    }
}
