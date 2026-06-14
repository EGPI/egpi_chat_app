<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class UserRealtimeEventBroadcasted implements ShouldBroadcastNow
{
    public function __construct(
        private readonly int $userId,
        private readonly string $eventName,
        private readonly array $payload
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
