<?php

namespace App\Services;

use App\Events\ChatSyncEventBroadcasted;
use App\Models\SyncEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChatSyncEventBroadcaster
{
    public function createForUser(
        int $userId,
        string $eventType,
        ?int $conversationId,
        ?int $messageId,
        ?array $payload,
        CarbonInterface $occurredAt
    ): SyncEvent {
        $event = SyncEvent::query()->create([
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => $occurredAt,
        ]);

        $this->broadcastAfterCommit($event->id);

        return $event;
    }

    /**
     * @param  iterable<int>  $userIds
     * @return Collection<int, SyncEvent>
     */
    public function createForUsers(
        iterable $userIds,
        string $eventType,
        ?int $conversationId,
        ?int $messageId,
        ?array $payload,
        CarbonInterface $occurredAt
    ): Collection {
        return collect($userIds)
            ->unique()
            ->values()
            ->map(fn (int $userId) => $this->createForUser(
                userId: $userId,
                eventType: $eventType,
                conversationId: $conversationId,
                messageId: $messageId,
                payload: $payload,
                occurredAt: $occurredAt
            ));
    }

    public function envelope(SyncEvent $event): array
    {
        return [
            'id' => $event->id,
            'event_id' => $event->id,
            'event_type' => $event->event_type,
            'conversation_id' => $event->conversation_id,
            'message_id' => $event->message_id,
            'payload' => $event->payload,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'created_at' => $event->created_at?->toISOString(),
        ];
    }

    private function broadcastAfterCommit(int $eventId): void
    {
        DB::afterCommit(function () use ($eventId): void {
            $event = SyncEvent::query()->find($eventId);

            if (! $event) {
                return;
            }

            try {
                broadcast(new ChatSyncEventBroadcasted(
                    userId: $event->user_id,
                    envelope: $this->envelope($event)
                ));
            } catch (Throwable $exception) {
                Log::warning('Failed to broadcast chat sync event.', [
                    'sync_event_id' => $event->id,
                    'event_type' => $event->event_type,
                    'user_id' => $event->user_id,
                    'exception' => $exception->getMessage(),
                ]);
            }
        });
    }
}
