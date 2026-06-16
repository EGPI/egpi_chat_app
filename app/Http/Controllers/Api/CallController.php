<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\MarkCallMissedIfStillRinging;
use App\Models\Call;
use App\Models\CallParticipant;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Services\CallLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    public function startAudio(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account is inactive.',
            ], 403);
        }

        if ($conversation->type !== 'direct') {
            return response()->json([
                'message' => 'Audio calls are only available in direct conversations.',
            ], 422);
        }

        $participants = $this->activeConversationParticipants($conversation);
        $callerParticipant = $participants->firstWhere('user_id', $user->id);

        if (! $callerParticipant) {
            return response()->json([
                'message' => 'You are not a participant in this conversation.',
            ], 403);
        }

        $calleeParticipant = $participants->firstWhere('user_id', '!=', $user->id);

        if (! $calleeParticipant || $participants->count() !== 2) {
            return response()->json([
                'message' => 'Direct conversation must have exactly two active participants.',
            ], 422);
        }

        $call = DB::transaction(function () use ($conversation, $user, $calleeParticipant) {
            $activeCallExists = Call::query()
                ->whereIn('status', ['ringing', 'accepted'])
                ->where(function ($query) use ($user, $calleeParticipant) {
                    $query->whereIn('caller_id', [$user->id, $calleeParticipant->user_id])
                        ->orWhereIn('callee_id', [$user->id, $calleeParticipant->user_id]);
                })
                ->lockForUpdate()
                ->exists();

            if ($activeCallExists) {
                abort(422, 'One of the participants already has an active call.');
            }

            $now = now();

            $call = Call::query()->create([
                'conversation_id' => $conversation->id,
                'caller_id' => $user->id,
                'callee_id' => $calleeParticipant->user_id,
                'type' => 'audio',
                'status' => 'ringing',
                'started_at' => $now,
            ]);

            CallParticipant::query()->create([
                'call_id' => $call->id,
                'user_id' => $user->id,
                'role' => 'caller',
                'status' => 'accepted',
                'joined_at' => $now,
            ]);

            CallParticipant::query()->create([
                'call_id' => $call->id,
                'user_id' => $calleeParticipant->user_id,
                'role' => 'callee',
                'status' => 'ringing',
            ]);

            return $call;
        });

        MarkCallMissedIfStillRinging::dispatch($call->id)->delay(now()->addSeconds(45));

        $call->load('participants.user:id,name,email,avatar_url');

        $this->callLifecycle()->broadcastToUser(
            $call->callee_id,
            'call.ringing',
            $call,
            ['call' => $this->callResponse($call)],
            $call->started_at ?? now()
        );

        return response()->json([
            'message' => 'Audio call started successfully.',
            'data' => $this->callResponse($call),
        ], 201);
    }

    public function accept(Request $request, Call $call): JsonResponse
    {
        $user = $request->user();

        if ($call->callee_id !== $user->id) {
            return response()->json([
                'message' => 'Only the callee can accept this call.',
            ], 403);
        }

        $call = DB::transaction(function () use ($call) {
            $lockedCall = Call::query()
                ->whereKey($call->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCall->status !== 'ringing') {
                abort(422, 'Only ringing calls can be accepted.');
            }

            $now = now();

            $lockedCall->update([
                'status' => 'accepted',
                'answered_at' => $now,
            ]);

            CallParticipant::query()
                ->where('call_id', $lockedCall->id)
                ->where('role', 'callee')
                ->update([
                    'status' => 'accepted',
                    'joined_at' => $now,
                    'updated_at' => $now,
                ]);

            return $lockedCall;
        });

        $call->load('participants.user:id,name,email,avatar_url');

        $this->callLifecycle()->broadcastToUser(
            $call->caller_id,
            'call.accepted',
            $call,
            ['call' => $this->callResponse($call)],
            $call->answered_at ?? now()
        );

        return response()->json([
            'message' => 'Call accepted successfully.',
            'data' => $this->callResponse($call),
        ]);
    }

    public function reject(Request $request, Call $call): JsonResponse
    {
        $user = $request->user();

        if ($call->callee_id !== $user->id) {
            return response()->json([
                'message' => 'Only the callee can reject this call.',
            ], 403);
        }

        $call = DB::transaction(function () use ($call, $user) {
            $lockedCall = Call::query()
                ->whereKey($call->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCall->status !== 'ringing') {
                abort(422, 'Only ringing calls can be rejected.');
            }

            $now = now();

            $lockedCall->update([
                'status' => 'rejected',
                'ended_at' => $now,
                'duration_seconds' => 0,
            ]);

            CallParticipant::query()
                ->where('call_id', $lockedCall->id)
                ->where('role', 'callee')
                ->update([
                    'status' => 'rejected',
                    'left_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->callLifecycle()->createCallLogMessage($lockedCall, $now);

            return $lockedCall;
        });

        $call->load('participants.user:id,name,email,avatar_url');

        $this->callLifecycle()->broadcastToCallUsers($call, 'call.rejected', [
            'call' => $this->callResponse($call),
        ], $call->ended_at ?? now());

        return response()->json([
            'message' => 'Call rejected successfully.',
            'data' => $this->callResponse($call),
        ]);
    }

    public function end(Request $request, Call $call): JsonResponse
    {
        $user = $request->user();

        if (! $this->isCallUser($call, $user->id)) {
            return response()->json([
                'message' => 'You are not a participant in this call.',
            ], 403);
        }

        $eventName = 'call.ended';

        $call = DB::transaction(function () use ($call, $user, &$eventName) {
            $lockedCall = Call::query()
                ->whereKey($call->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedCall->status, ['ringing', 'accepted'], true)) {
                abort(422, 'Only active calls can be ended.');
            }

            $now = now();

            if ($lockedCall->status === 'accepted') {
                $eventName = 'call.ended';

                $lockedCall->update([
                    'status' => 'ended',
                    'ended_at' => $now,
                    'duration_seconds' => 0,
                ]);

                $lockedCall->refresh();

                $lockedCall->update([
                    'duration_seconds' => $this->connectedDurationSeconds($lockedCall),
                ]);
            } else {
                $eventName = 'call.cancelled';

                $lockedCall->update([
                    'status' => 'cancelled',
                    'ended_at' => $now,
                    'duration_seconds' => 0,
                ]);
            }

            CallParticipant::query()
                ->where('call_id', $lockedCall->id)
                ->where('user_id', $user->id)
                ->update([
                    'status' => 'left',
                    'left_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->callLifecycle()->createCallLogMessage($lockedCall, $now);

            return $lockedCall;
        });

        $call->load('participants.user:id,name,email,avatar_url');

        $this->callLifecycle()->broadcastToCallUsers($call, $eventName, [
            'call' => $this->callResponse($call),
        ], $call->ended_at ?? now());

        return response()->json([
            'message' => 'Call ended successfully.',
            'data' => $this->callResponse($call),
        ]);
    }

    public function signal(Request $request, Call $call): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['offer', 'answer', 'ice_candidate'])],
            'payload' => ['required', 'array'],
        ]);

        $user = $request->user();

        if (! $this->isCallUser($call, $user->id)) {
            return response()->json([
                'message' => 'You are not a participant in this call.',
            ], 403);
        }

        $recipientId = $call->caller_id === $user->id
            ? $call->callee_id
            : $call->caller_id;

        if (! $recipientId) {
            return response()->json([
                'message' => 'The other call participant is no longer available.',
            ], 422);
        }

        $eventName = 'call.'.$validated['type'];

        $this->callLifecycle()->broadcastToUser($recipientId, $eventName, $call, [
            'from_user_id' => $user->id,
            'type' => $validated['type'],
            'payload' => $validated['payload'],
        ], now());

        return response()->json([
            'message' => 'Call signal sent successfully.',
        ]);
    }

    public function show(Request $request, Call $call): JsonResponse
    {
        if (! $this->isCallUser($call, $request->user()->id)) {
            return response()->json([
                'message' => 'You are not a participant in this call.',
            ], 403);
        }

        $call->load('participants.user:id,name,email,avatar_url');

        return response()->json([
            'data' => $this->callResponse($call),
        ]);
    }

    private function activeConversationParticipants(Conversation $conversation)
    {
        return ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->whereNull('left_at')
            ->get();
    }

    private function isCallUser(Call $call, int $userId): bool
    {
        return $call->caller_id === $userId || $call->callee_id === $userId;
    }

    private function callResponse(Call $call): array
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
            'participants' => $call->participants
                ->map(fn (CallParticipant $participant) => [
                    'user_id' => $participant->user_id,
                    'role' => $participant->role,
                    'status' => $participant->status,
                    'joined_at' => $participant->joined_at?->toISOString(),
                    'left_at' => $participant->left_at?->toISOString(),
                    'user' => $participant->user ? [
                        'id' => $participant->user->id,
                        'name' => $participant->user->name,
                        'email' => $participant->user->email,
                        'avatar_url' => $participant->user->avatar_url,
                    ] : null,
                ])
                ->values(),
        ];
    }

    private function integerDurationSeconds(float|int $seconds): int
    {
        return max(0, (int) floor($seconds));
    }

    private function connectedDurationSeconds(Call $call): int
    {
        if (! $call->answered_at || ! $call->ended_at) {
            return 0;
        }

        return $this->integerDurationSeconds($call->answered_at->diffInSeconds($call->ended_at));
    }

    private function callLifecycle(): CallLifecycleService
    {
        return app(CallLifecycleService::class);
    }
}
