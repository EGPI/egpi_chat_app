<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncEvent;
use App\Services\ChatSyncEventBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'after_event_id' => [
                'nullable',
                'integer',
                'min:0',
            ],
            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $afterEventId = $validated['after_event_id'] ?? 0;
        $limit = $validated['limit'] ?? 100;

        $events = SyncEvent::query()
            ->where('user_id', $user->id)
            ->where('id', '>', $afterEventId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $events->count() > $limit;

        $events = $events
            ->take($limit)
            ->values();

        $nextEventId = $events->last()?->id ?? $afterEventId;

        $syncBroadcaster = app(ChatSyncEventBroadcaster::class);

        return response()->json([
            'events' => $events
                ->map(fn (SyncEvent $event) => $syncBroadcaster->envelope($event))
                ->values(),
            'next_event_id' => $nextEventId,
            'has_more' => $hasMore,
        ]);
    }
}
