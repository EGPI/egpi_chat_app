<?php

namespace App\Jobs;

use App\Models\Call;
use App\Models\CallParticipant;
use App\Services\CallLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MarkCallMissedIfStillRinging implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $callId
    ) {}

    public function handle(CallLifecycleService $callLifecycle): void
    {
        $call = DB::transaction(function () use ($callLifecycle) {
            $lockedCall = Call::query()
                ->whereKey($this->callId)
                ->lockForUpdate()
                ->first();

            if (! $lockedCall || $lockedCall->status !== 'ringing') {
                return null;
            }

            $now = now();

            $lockedCall->update([
                'status' => 'missed',
                'ended_at' => $now,
                'duration_seconds' => 0,
            ]);

            CallParticipant::query()
                ->where('call_id', $lockedCall->id)
                ->where('role', 'callee')
                ->update([
                    'status' => 'missed',
                    'left_at' => $now,
                    'updated_at' => $now,
                ]);

            $callLifecycle->createCallLogMessage($lockedCall, $now);

            return $lockedCall;
        });

        if (! $call) {
            return;
        }

        $call->refresh();

        $callLifecycle->broadcastToCallUsers($call, 'call.missed', [
            'call' => $callLifecycle->callPayload($call),
        ], $call->ended_at ?? now());
    }
}
