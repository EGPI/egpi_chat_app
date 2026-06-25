<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendMessagePushNotification;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\MessageReceipt;
use App\Services\ChatSyncEventBroadcaster;
use App\Services\ConversationSyncPayload;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'client_message_id' => [
                'required',
                'uuid',
            ],
            'body' => [
                'required',
                'string',
                'min:1',
                'max:4000',
            ],
        ]);

        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $body = trim($validated['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Message body cannot be empty.',
            ], 422);
        }

        $senderParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $senderParticipant) {
            return response()->json([
                'message' => 'You are not a participant in this conversation.',
            ], 403);
        }

        if (! $this->canSendMessage($conversation, $senderParticipant)) {
            return response()->json([
                'message' => 'You are not allowed to send messages in this conversation.',
            ], 403);
        }

        $existingMessage = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('sender_id', $user->id)
            ->where('client_message_id', $validated['client_message_id'])
            ->first();

        if ($existingMessage) {
            $existingMessage->load([
                'sender:id,name,email',
                'receipts:id,message_id,user_id,delivered_at,read_at',
            ]);

            return response()->json([
                'message' => 'Message already exists.',
                'duplicate' => true,
                'data' => $this->messageResponse($existingMessage),
            ]);
        }

        try {
            $message = DB::transaction(function () use ($conversation, $user, $validated, $body) {
                $syncBroadcaster = app(ChatSyncEventBroadcaster::class);
                $now = now();

                $message = Message::query()->create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'client_message_id' => $validated['client_message_id'],
                    'type' => 'text',
                    'body' => $body,
                    'payload' => null,
                    'reply_to_message_id' => null,
                    'server_received_at' => $now,
                    'sent_at' => $now,
                ]);

                $activeParticipants = ConversationParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereNull('left_at')
                    ->get();

                foreach ($activeParticipants as $participant) {
                    if ($participant->user_id === $user->id) {
                        continue;
                    }

                    MessageReceipt::query()->create([
                        'message_id' => $message->id,
                        'conversation_id' => $conversation->id,
                        'user_id' => $participant->user_id,
                        'delivered_at' => null,
                        'read_at' => null,
                    ]);

                    $participant->increment('unread_count');
                }

                $conversation->update([
                    'last_message_id' => $message->id,
                    'last_message_sender_id' => $user->id,
                    'last_message_preview' => $this->makePreview($body),
                    'last_message_at' => $now,
                ]);

                foreach ($activeParticipants as $participant) {
                    $syncBroadcaster->createForUser(
                        userId: $participant->user_id,
                        eventType: 'message.created',
                        conversationId: $conversation->id,
                        messageId: $message->id,
                        payload: [
                            'message_id' => $message->id,
                            'conversation_id' => $conversation->id,
                            'sender_id' => $user->id,
                            'type' => 'text',
                            'body' => $body,
                            'client_message_id' => $message->client_message_id,
                            'server_received_at' => $message->server_received_at?->toISOString(),
                            'sent_at' => $message->sent_at?->toISOString(),
                        ],
                        occurredAt: $now
                    );
                }

                $conversationParticipants = ConversationParticipant::query()
                    ->where('conversation_id', $conversation->id)
                    ->whereNull('left_at')
                    ->with('conversation.participants.user:id,name,email,avatar_url')
                    ->get();

                foreach ($conversationParticipants as $participant) {
                    $syncBroadcaster->createForUser(
                        userId: $participant->user_id,
                        eventType: 'conversation.updated',
                        conversationId: $conversation->id,
                        messageId: $message->id,
                        payload: $this->conversationUpdatedPayload($participant),
                        occurredAt: $now
                    );
                }

                return $message;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                $existingMessage = Message::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('sender_id', $user->id)
                    ->where('client_message_id', $validated['client_message_id'])
                    ->first();

                if ($existingMessage) {
                    $existingMessage->load([
                        'sender:id,name,email',
                        'receipts:id,message_id,user_id,delivered_at,read_at',
                    ]);

                    return response()->json([
                        'message' => 'Message already exists.',
                        'duplicate' => true,
                        'data' => $this->messageResponse($existingMessage),
                    ]);
                }
            }

            throw $exception;
        }

        $message->load([
            'sender:id,name,email',
            'receipts:id,message_id,user_id,delivered_at,read_at',
        ]);

        $this->dispatchPushNotifications($message);

        return response()->json([
            'message' => 'Message sent successfully.',
            'duplicate' => false,
            'data' => $this->messageResponse($message),
        ], 201);
    }

    private function canSendMessage(Conversation $conversation, ConversationParticipant $participant): bool
    {
        if ($conversation->type === 'direct') {
            return true;
        }

        if ($conversation->type === 'group') {
            return in_array($participant->role, ['owner', 'admin', 'member'], true);
        }

        if ($conversation->type === 'announcement') {
            return in_array($participant->role, ['owner', 'admin'], true);
        }

        return false;
    }

    private function makePreview(string $body): string
    {
        return mb_strimwidth($body, 0, 120, '...');
    }

    private function messageResponse(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'client_message_id' => $message->client_message_id,
            'type' => $message->type,
            'body' => $message->body,
            'payload' => $message->payload,
            'server_received_at' => $message->server_received_at?->toISOString(),
            'sent_at' => $message->sent_at?->toISOString(),
            'edited_at' => $message->edited_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),

            // For Flutter tick system:
            // returned from this endpoint means server accepted/stored it.
            'status' => 'sent',

            'sender' => $message->sender ? [
                'id' => $message->sender->id,
                'name' => $message->sender->name,
                'email' => $message->sender->email,
            ] : null,

            'receipts' => $message->receipts->map(fn (MessageReceipt $receipt) => [
                'user_id' => $receipt->user_id,
                'delivered_at' => $receipt->delivered_at?->toISOString(),
                'read_at' => $receipt->read_at?->toISOString(),
            ])->values(),
        ];
    }

    public function index(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'before_message_id' => [
                'nullable',
                'integer',
                'exists:messages,id',
            ],
            'limit' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],
        ]);

        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'You are not a participant in this conversation.',
            ], 403);
        }

        $limit = $validated['limit'] ?? 30;

        $messagesQuery = Message::query()
            ->where('conversation_id', $conversation->id)
            ->with([
                'sender:id,name,email',
                'receipts:id,message_id,user_id,delivered_at,read_at',
            ]);

        if (! empty($validated['before_message_id'])) {
            $cursorMessageExistsInConversation = Message::query()
                ->where('id', $validated['before_message_id'])
                ->where('conversation_id', $conversation->id)
                ->exists();

            if (! $cursorMessageExistsInConversation) {
                return response()->json([
                    'message' => 'before_message_id does not belong to this conversation.',
                ], 422);
            }

            $messagesQuery->where('id', '<', $validated['before_message_id']);
        }

        $messages = $messagesQuery
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $messages->count() > $limit;

        $messages = $messages
            ->take($limit)
            ->sortBy('id')
            ->values();

        return response()->json([
            'data' => $messages->map(fn (Message $message) => $this->messageResponse($message)),
            'meta' => [
                'limit' => $limit,
                'has_more' => $hasMore,
                'next_before_message_id' => $hasMore
                    ? $messages->first()?->id
                    : null,
            ],
        ]);
    }

    public function markDelivered(Request $request, Message $message): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $message->load('conversation');

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'You are not a participant in this conversation.',
            ], 403);
        }

        if ($message->sender_id === $user->id) {
            return response()->json([
                'message' => 'Sender cannot mark their own message as delivered.',
            ], 422);
        }

        $receipt = MessageReceipt::query()
            ->where('message_id', $message->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $receipt) {
            return response()->json([
                'message' => 'Receipt was not found for this user and message.',
            ], 404);
        }

        $wasAlreadyDelivered = ! is_null($receipt->delivered_at);

        if (! $wasAlreadyDelivered) {
            DB::transaction(function () use ($message, $receipt, $user): void {
                $syncBroadcaster = app(ChatSyncEventBroadcaster::class);
                $now = now();

                $receipt->update([
                    'delivered_at' => $now,
                ]);

                $syncBroadcaster->createForUser(
                    userId: $message->sender_id,
                    eventType: 'message.delivered',
                    conversationId: $message->conversation_id,
                    messageId: $message->id,
                    payload: [
                        'message_id' => $message->id,
                        'conversation_id' => $message->conversation_id,
                        'delivered_by_user_id' => $user->id,
                        'delivered_at' => $now->toISOString(),
                    ],
                    occurredAt: $now
                );
            });
        }

        $receipt->refresh();

        return response()->json([
            'message' => $wasAlreadyDelivered
                ? 'Message was already marked as delivered.'
                : 'Message marked as delivered successfully.',
            'data' => [
                'message_id' => $receipt->message_id,
                'conversation_id' => $receipt->conversation_id,
                'user_id' => $receipt->user_id,
                'delivered_at' => $receipt->delivered_at?->toISOString(),
                'read_at' => $receipt->read_at?->toISOString(),
            ],
        ]);
    }

    public function markConversationRead(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return response()->json([
                'message' => 'You are not a participant in this conversation.',
            ], 403);
        }

        $latestMessage = Message::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('id')
            ->first();

        if (! $latestMessage) {
            $now = now();

            DB::transaction(function () use ($participant, $conversation, $now): void {
                $participant->update([
                    'last_read_at' => $now,
                    'unread_count' => 0,
                    'last_read_message_id' => null,
                ]);

                app(ChatSyncEventBroadcaster::class)->createForUser(
                    userId: $participant->user_id,
                    eventType: 'conversation.updated',
                    conversationId: $conversation->id,
                    messageId: null,
                    payload: $this->conversationUpdatedPayload(
                        $participant->fresh()->load('conversation.participants.user:id,name,email,avatar_url')
                    ),
                    occurredAt: $now
                );
            });

            return response()->json([
                'message' => 'Conversation has no messages.',
                'data' => [
                    'conversation_id' => $conversation->id,
                    'last_read_message_id' => null,
                    'unread_count' => 0,
                    'marked_read_count' => 0,
                ],
            ]);
        }

        $now = now();

        $changedReceiptIds = [];

        DB::transaction(function () use (
            $conversation,
            $user,
            $participant,
            $latestMessage,
            $now,
            &$changedReceiptIds
        ) {
            $syncBroadcaster = app(ChatSyncEventBroadcaster::class);

            $receipts = MessageReceipt::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->whereHas('message', function ($query) use ($latestMessage) {
                    $query->where('id', '<=', $latestMessage->id);
                })
                ->get();

            $changedReceiptIds = $receipts->pluck('id')->all();

            if ($receipts->isNotEmpty()) {
                MessageReceipt::query()
                    ->whereIn('id', $changedReceiptIds)
                    ->update([
                        'delivered_at' => DB::raw('COALESCE(delivered_at, NOW())'),
                        'read_at' => $now,
                        'updated_at' => $now,
                    ]);
            }

            $participant->update([
                'last_read_at' => $now,
                'last_read_message_id' => $latestMessage->id,
                'unread_count' => 0,
            ]);

            $activeParticipants = ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->whereNull('left_at')
                ->get();

            foreach ($activeParticipants as $activeParticipant) {
                $syncBroadcaster->createForUser(
                    userId: $activeParticipant->user_id,
                    eventType: 'message.read',
                    conversationId: $conversation->id,
                    messageId: $latestMessage->id,
                    payload: [
                        'conversation_id' => $conversation->id,
                        'read_by_user_id' => $user->id,
                        'last_read_message_id' => $latestMessage->id,
                        'read_at' => $now->toISOString(),
                        'marked_read_count' => count($changedReceiptIds),
                    ],
                    occurredAt: $now
                );
            }

            $syncBroadcaster->createForUser(
                userId: $user->id,
                eventType: 'conversation.updated',
                conversationId: $conversation->id,
                messageId: $latestMessage->id,
                payload: $this->conversationUpdatedPayload(
                    $participant->fresh()->load('conversation.participants.user:id,name,email,avatar_url')
                ),
                occurredAt: $now
            );
        });

        return response()->json([
            'message' => 'Conversation marked as read successfully.',
            'data' => [
                'conversation_id' => $conversation->id,
                'last_read_message_id' => $latestMessage->id,
                'last_read_at' => $now->toISOString(),
                'unread_count' => 0,
                'marked_read_count' => count($changedReceiptIds),
            ],
        ]);
    }

    private function conversationUpdatedPayload(ConversationParticipant $participant): array
    {
        return app(ConversationSyncPayload::class)->forParticipant($participant);
    }

    private function dispatchPushNotifications(Message $message): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $message->sender_id)
            ->pluck('user_id')
            ->each(fn (int $userId) => SendMessagePushNotification::dispatch($message->id, $userId));
    }
}
