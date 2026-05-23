<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $participants = ConversationParticipant::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('left_at')
            ->with([
                'conversation.participants.user:id,name,email,avatar_url',
                'conversation.lastMessageSender:id,name,email',
            ])
            ->whereHas('conversation', function (Builder $query) {
                $query->whereNull('deleted_at');
            })
            ->join('conversations', 'conversation_participants.conversation_id', '=', 'conversations.id')
            ->orderByDesc(DB::raw('COALESCE(conversations.last_message_at, conversations.created_at)'))
            ->select('conversation_participants.*')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $participants->getCollection()->map(function (ConversationParticipant $participant) use ($request) {
                return $this->conversationListItem($participant, $request->user()->id);
            }),
            'meta' => [
                'current_page' => $participants->currentPage(),
                'per_page' => $participants->perPage(),
                'total' => $participants->total(),
                'last_page' => $participants->lastPage(),
            ],
        ]);
    }

public function createDirect(Request $request): JsonResponse
{
    $validated = $request->validate([
        'user_id' => [
            'required',
            'integer',
            'exists:users,id',
            Rule::notIn([$request->user()->id]),
        ],
    ]);

    $otherUser = User::query()
        ->where('id', $validated['user_id'])
        ->where('is_active', true)
        ->firstOrFail();

    $authUserId = $request->user()->id;

    $existingConversationId = ConversationParticipant::query()
        ->join('conversations', 'conversation_participants.conversation_id', '=', 'conversations.id')
        ->where('conversations.type', 'direct')
        ->whereNull('conversations.deleted_at')
        ->whereNull('conversation_participants.left_at')
        ->whereIn('conversation_participants.user_id', [$authUserId, $otherUser->id])
        ->groupBy('conversation_participants.conversation_id')
        ->havingRaw('COUNT(DISTINCT conversation_participants.user_id) = 2')
        ->value('conversation_participants.conversation_id');

    if ($existingConversationId) {
        $participant = ConversationParticipant::query()
            ->where('conversation_id', $existingConversationId)
            ->where('user_id', $authUserId)
            ->with([
                'conversation.participants.user:id,name,email,avatar_url',
                'conversation.lastMessageSender:id,name,email',
            ])
            ->firstOrFail();

        return response()->json([
            'message' => 'Direct conversation already exists.',
            'data' => $this->conversationListItem($participant, $authUserId),
        ]);
    }

    $conversation = DB::transaction(function () use ($request, $otherUser) {
        $conversation = Conversation::query()->create([
            'type' => 'direct',
            'title' => null,
            'created_by' => $request->user()->id,
            'metadata' => null,
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $otherUser->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        return $conversation;
    });

    $participant = ConversationParticipant::query()
        ->where('conversation_id', $conversation->id)
        ->where('user_id', $authUserId)
        ->with([
            'conversation.participants.user:id,name,email,avatar_url',
            'conversation.lastMessageSender:id,name,email',
        ])
        ->firstOrFail();

    return response()->json([
        'message' => 'Direct conversation created successfully.',
        'data' => $this->conversationListItem($participant, $authUserId),
    ], 201);
}
    public function createGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'member_ids' => ['nullable', 'array', 'max:99'],
            'member_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
                Rule::notIn([$request->user()->id]),
            ],
        ]);

        return $this->createMultiUserConversation(
            request: $request,
            type: 'group',
            title: $validated['title'],
            memberIds: $validated['member_ids'] ?? []
        );
    }

    public function createAnnouncement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:255'],
            'member_ids' => ['nullable', 'array', 'max:99'],
            'member_ids.*' => [
                'integer',
                'distinct',
                'exists:users,id',
                Rule::notIn([$request->user()->id]),
            ],
        ]);

        return $this->createMultiUserConversation(
            request: $request,
            type: 'announcement',
            title: $validated['title'],
            memberIds: $validated['member_ids'] ?? []
        );
    }

    public function addMember(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
        ]);

        $this->ensureGroupOrAnnouncement($conversation);
        $this->ensureAdminOrOwner($conversation, $request->user()->id);

        $user = User::query()
            ->where('id', $validated['user_id'])
            ->where('is_active', true)
            ->firstOrFail();

        $activeCount = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->count();

        if ($activeCount >= 100) {
            return response()->json([
                'message' => 'Conversation member limit reached.',
            ], 422);
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->first();

        if ($participant && is_null($participant->left_at)) {
            return response()->json([
                'message' => 'User is already a member.',
            ], 422);
        }

        if ($participant) {
            $participant->update([
                'role' => 'member',
                'joined_at' => now(),
                'left_at' => null,
                'unread_count' => 0,
                'last_read_at' => null,
                'last_read_message_id' => null,
            ]);
        } else {
            $participant = ConversationParticipant::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'role' => 'member',
                'joined_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Member added successfully.',
            'data' => [
                'conversation_id' => $conversation->id,
                'user_id' => $participant->user_id,
                'role' => $participant->role,
                'joined_at' => $participant->joined_at?->toISOString(),
            ],
        ], 201);
    }

    public function removeMember(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureGroupOrAnnouncement($conversation);
        $this->ensureAdminOrOwner($conversation, $request->user()->id);

        $actorParticipant = $this->getActiveParticipant($conversation, $request->user()->id);
        $targetParticipant = $this->getActiveParticipant($conversation, $user->id);

        if (! $targetParticipant) {
            return response()->json([
                'message' => 'User is not an active member of this conversation.',
            ], 404);
        }

        if ($targetParticipant->role === 'owner') {
            return response()->json([
                'message' => 'Owner cannot be removed.',
            ], 403);
        }

        if ($targetParticipant->role === 'admin' && $actorParticipant?->role !== 'owner') {
            return response()->json([
                'message' => 'Only the owner can remove an admin.',
            ], 403);
        }

        if ($request->user()->id === $user->id && $targetParticipant->role === 'owner') {
            return response()->json([
                'message' => 'Owner cannot leave using this endpoint.',
            ], 403);
        }

        $targetParticipant->update([
            'left_at' => now(),
        ]);

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }

    public function promoteMember(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $this->ensureGroupOrAnnouncement($conversation);
        $this->ensureAdminOrOwner($conversation, $request->user()->id);

        $targetParticipant = $this->getActiveParticipant($conversation, $user->id);

        if (! $targetParticipant) {
            return response()->json([
                'message' => 'User is not an active member of this conversation.',
            ], 404);
        }

        if ($targetParticipant->role === 'owner') {
            return response()->json([
                'message' => 'Owner is already the highest role.',
            ], 422);
        }

        if ($targetParticipant->role === 'admin') {
            return response()->json([
                'message' => 'User is already an admin.',
            ]);
        }

        $targetParticipant->update([
            'role' => 'admin',
        ]);

        return response()->json([
            'message' => 'Member promoted to admin successfully.',
            'data' => [
                'conversation_id' => $conversation->id,
                'user_id' => $targetParticipant->user_id,
                'role' => $targetParticipant->role,
            ],
        ]);
    }

    private function createMultiUserConversation(
        Request $request,
        string $type,
        string $title,
        array $memberIds
    ): JsonResponse {
        $memberIds = collect($memberIds)
            ->unique()
            ->values();

        $activeUsersCount = User::query()
            ->whereIn('id', $memberIds)
            ->where('is_active', true)
            ->count();

        if ($activeUsersCount !== $memberIds->count()) {
            return response()->json([
                'message' => 'One or more selected users are invalid or inactive.',
            ], 422);
        }

        $conversation = DB::transaction(function () use ($request, $type, $title, $memberIds) {
            $conversation = Conversation::query()->create([
                'type' => $type,
                'title' => $title,
                'created_by' => $request->user()->id,
                'metadata' => null,
            ]);

            ConversationParticipant::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $request->user()->id,
                'role' => 'owner',
                'joined_at' => now(),
            ]);

            foreach ($memberIds as $memberId) {
                ConversationParticipant::query()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $memberId,
                    'role' => 'member',
                    'joined_at' => now(),
                ]);
            }

            return $conversation;
        });

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $request->user()->id)
            ->with([
                'conversation.participants.user:id,name,email,avatar_url',
                'conversation.lastMessageSender:id,name,email',
            ])
            ->firstOrFail();

        return response()->json([
            'message' => ucfirst($type) . ' conversation created successfully.',
            'data' => $this->conversationListItem($participant, $request->user()->id),
        ], 201);
    }

    private function conversationListItem(ConversationParticipant $participant, int $authUserId): array
    {
        $conversation = $participant->conversation;

        return [
            'id' => $conversation->id,
            'type' => $conversation->type,
            'title' => $this->resolveConversationTitle($conversation, $authUserId),
            'last_message_preview' => $conversation->last_message_preview,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'last_message_sender_id' => $conversation->last_message_sender_id,
            'unread_count' => $participant->unread_count,
            'my_role' => $participant->role,
            'created_at' => $conversation->created_at?->toISOString(),
            'updated_at' => $conversation->updated_at?->toISOString(),
        ];
    }

    private function resolveConversationTitle(Conversation $conversation, int $authUserId): ?string
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

    private function ensureGroupOrAnnouncement(Conversation $conversation): void
    {
        abort_unless(
            in_array($conversation->type, ['group', 'announcement'], true),
            422,
            'This action is only available for group or announcement conversations.'
        );
    }

    private function ensureAdminOrOwner(Conversation $conversation, int $userId): void
    {
        $participant = $this->getActiveParticipant($conversation, $userId);

        abort_unless(
            $participant && in_array($participant->role, ['owner', 'admin'], true),
            403,
            'You do not have permission to manage this conversation.'
        );
    }

    private function getActiveParticipant(Conversation $conversation, int $userId): ?ConversationParticipant
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->first();
    }
}