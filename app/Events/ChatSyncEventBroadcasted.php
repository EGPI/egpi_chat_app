<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ChatSyncEventBroadcasted implements ShouldBroadcastNow
{
    public function __construct(
        private readonly int $userId,
        private readonly array $envelope
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->envelope['event_type'];
    }

    public function broadcastWith(): array
    {
        return $this->envelope;
    }
}
