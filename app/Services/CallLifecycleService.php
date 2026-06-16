<?php

namespace App\Services;

use App\Events\UserRealtimeEventBroadcasted;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReceipt;
use Carbon\CarbonInterface;

class CallLifecycleService
{
    public function broadcastToCallUsers(Call $call, string $eventType, array $payload, CarbonInterface $occurredAt): void
    {
        foreach (array_filter([$call->caller_id, $call->callee_id]) as $userId) {
            $this->broadcastToUser((int) $userId, $eventType, $call, $payload, $occurredAt);
        }
    }

    public function broadcastToUser(
        ?int $userId,
        string $eventType,
        Call $call,
        array $payload,
        CarbonInterface $occurredAt
    ): void {
        if (! $userId) {
            return;
        }

        broadcast(new UserRealtimeEventBroadcasted($userId, $eventType, [
            'event_type' => $eventType,
            'event_id' => null,
            'call_id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'payload' => $payload,
            'occurred_at' => $occurredAt->toISOString(),
        ]));
    }

    public function createCallLogMessage(Call $call, CarbonInterface $occurredAt): Message
    {
        $existingMessage = Message::query()
            ->where('conversation_id', $call->conversation_id)
            ->where('type', 'call')
            ->where('payload->call_id', $call->id)
            ->lockForUpdate()
            ->first();

        if ($existingMessage) {
            return $existingMessage;
        }

        $message = Message::query()->create([
            'conversation_id' => $call->conversation_id,
            'sender_id' => $call->caller_id,
            'client_message_id' => null,
            'type' => 'call',
            'body' => $call->status === 'missed' ? 'Missed audio call' : 'Audio call',
            'payload' => $this->callMessagePayload($call),
            'reply_to_message_id' => null,
            'server_received_at' => $occurredAt,
            'sent_at' => $occurredAt,
        ]);

        $activeParticipants = ConversationParticipant::query()
            ->where('conversation_id', $call->conversation_id)
            ->whereNull('left_at')
            ->get();

        foreach ($activeParticipants as $participant) {
            if ($participant->user_id === $call->caller_id) {
                continue;
            }

            MessageReceipt::query()->create([
                'message_id' => $message->id,
                'conversation_id' => $call->conversation_id,
                'user_id' => $participant->user_id,
                'delivered_at' => null,
                'read_at' => null,
            ]);

            $participant->increment('unread_count');
        }

        $conversation = $call->conversation()->firstOrFail();

        $conversation->update([
            'last_message_id' => $message->id,
            'last_message_sender_id' => $call->caller_id,
            'last_message_preview' => mb_strimwidth($message->body, 0, 120, '...'),
            'last_message_at' => $occurredAt,
        ]);

        $message->load('sender:id,name,email');

        $syncBroadcaster = app(ChatSyncEventBroadcaster::class);

        foreach ($activeParticipants as $participant) {
            $syncBroadcaster->createForUser(
                userId: $participant->user_id,
                eventType: 'message.created',
                conversationId: $call->conversation_id,
                messageId: $message->id,
                payload: $this->messageSyncPayload($message),
                occurredAt: $occurredAt
            );
        }

        $this->createConversationUpdatedEvents($conversation, $occurredAt, $message->id);

        return $message;
    }

    public function callPayload(Call $call): array
    {
        return [
            'id' => $call->id,
            'conversation_id' => $call->conversation_id,
            'caller_id' => $call->caller_id,
            'callee_id' => $call->callee_id,
            'type' => $call->type,
            'status' => $call->status,
            'started_at' => $call->started_at?->toISOString(),
            'answered_at' => $call->answered_at?->toISOString(),
            'ended_at' => $call->ended_at?->toISOString(),
            'duration_seconds' => $call->duration_seconds,
            'created_at' => $call->created_at?->toISOString(),
            'updated_at' => $call->updated_at?->toISOString(),
        ];
    }

    private function callMessagePayload(Call $call): array
    {
        return [
            'call_id' => $call->id,
            'call_type' => $call->type,
            'call_status' => $call->status,
            'caller_id' => $call->caller_id,
            'callee_id' => $call->callee_id,
            'duration_seconds' => $call->duration_seconds,
            'started_at' => $call->started_at?->toISOString(),
            'answered_at' => $call->answered_at?->toISOString(),
            'ended_at' => $call->ended_at?->toISOString(),
        ];
    }

    private function messageSyncPayload(Message $message): array
    {
        return [
            'id' => $message->id,
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'client_message_id' => $message->client_message_id,
            'type' => $message->type,
            'body' => $message->body,
            'payload' => $message->payload,
            'server_received_at' => $message->server_received_at?->toISOString(),
            'sent_at' => $message->sent_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
            'status' => 'sent',
            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'email' => $message->sender->email,
            ] : null,
        ];
    }

    private function createConversationUpdatedEvents(
        Conversation $conversation,
        CarbonInterface $occurredAt,
        ?int $messageId = null
    ): void {
        $syncBroadcaster = app(ChatSyncEventBroadcaster::class);
        $payloadFactory = app(ConversationSyncPayload::class);

        $participants = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->with([
                'conversation.participants.user:id,name,email,avatar_url',
                'conversation.lastMessageSender:id,name,email',
            ])
            ->get();

        foreach ($participants as $participant) {
            $syncBroadcaster->createForUser(
                userId: $participant->user_id,
                eventType: 'conversation.updated',
                conversationId: $conversation->id,
                messageId: $messageId,
                payload: $payloadFactory->forParticipant($participant),
                occurredAt: $occurredAt
            );
        }
    }
}
