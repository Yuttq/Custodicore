<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/announcements — facility announcements for the mobile Home
 * screen. Content lives in config/visitation.php (no database table yet).
 * Field names (title/body/category/createdAt) follow the same shape as
 * NotificationApiController's payload so the app can reuse its patterns.
 */
class AnnouncementApiController extends Controller
{
    public function index(): JsonResponse
    {
        $tz = config('visitation.timezone', 'Asia/Manila');

        $announcements = collect(config('visitation.announcements', []))
            ->filter(fn ($a) => is_array($a) && ! empty($a['id']) && ! empty($a['title']))
            // Pinned first, then newest first.
            ->sortByDesc(fn ($a) => (empty($a['pinned']) ? '0' : '1').'|'.($a['published_at'] ?? ''))
            ->map(fn ($a) => [
                'id' => (string) $a['id'],
                'title' => (string) $a['title'],
                'body' => (string) ($a['body'] ?? ''),
                'category' => (string) ($a['category'] ?? 'general'),
                'pinned' => (bool) ($a['pinned'] ?? false),
                'createdAt' => ! empty($a['published_at'])
                    ? CarbonImmutable::parse($a['published_at'], $tz)->toIso8601String()
                    : null,
            ])
            ->values();

        return response()->json(['announcements' => $announcements]);
    }
}
