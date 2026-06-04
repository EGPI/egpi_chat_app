<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationParticipant;

class ConversationSyncPayload
{
    public function forParticipant(ConversationParticipant $participant): array
    {
        $conversation = $participant->conversation;

        return [
            'id' => $conversation->id,
            'conversation_id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $this->resolveTitle($conversation, $participant->user_id),
            'last_message_id' => $conversation->last_message_id,
            'last_message_preview' => $conversation->last_message_preview,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'last_message_sender_id' => $conversation->last_message_sender_id,
            'unread_count' => $participant->unread_count,
            'my_role' => $participant->role,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    private function resolveTitle(Conversation $conversation, int $authUserId): ?string
    {
        if ($conversation->type !== 'direct') {
            return $conversation->title;
        }

        $otherParticipant = $conversation->participants
            ->firstWhere('user_id', '!=', $authUserId);

        return $otherParticipant?->user?->name
            ?? $otherParticipant?->user?->email
            ?? 'Direct chat';
    }
}
