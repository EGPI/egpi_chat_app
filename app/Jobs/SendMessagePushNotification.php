<?php

namespace App\Jobs;

use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\UserDevice;
use App\Services\FirebaseCloudMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMessagePushNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $messageId,
        public readonly int $recipientUserId
    ) {}

    public function handle(FirebaseCloudMessagingService $fcm): void
    {
        $message = Message::query()
            ->with([
                'sender:id,name,email',
                'conversation.participants.user:id,name,email',
            ])
            ->find($this->messageId);

        if (! $message || $message->sender_id === $this->recipientUserId) {
            return;
        }

        $participant = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $this->recipientUserId)
            ->whereNull('left_at')
            ->first();

        if (! $participant) {
            return;
        }

        $devices = UserDevice::query()
            ->where('user_id', $this->recipientUserId)
            ->where('is_active', true)
            ->whereNull('revoked_at')
            ->get();

        if ($devices->isEmpty()) {
            return;
        }

        $muted = $participant->is_muted
            && (is_null($participant->muted_until) || $participant->muted_until->isFuture());

        foreach ($devices as $device) {
            $fcm->send($device, $this->messageForDevice($message, $participant, $device, $muted));
        }
    }

    private function messageForDevice(
        Message $message,
        ConversationParticipant $participant,
        UserDevice $device,
        bool $muted
    ): array {
        $title = $this->title($message);
        $body = $this->body($message);
        $badge = max(0, $participant->unread_count);

        $payload = [
            'data' => [
                'type' => 'message',
                'message_id' => (string) $message->id,
                'conversation_id' => (string) $message->conversation_id,
                'sender_id' => (string) $message->sender_id,
                'sender_name' => (string) ($message->sender?->name ?? $message->sender?->email ?? 'Unknown'),
                'conversation_type' => (string) $message->conversation->type,
                'title' => $title,
                'body' => $body,
                'unread_count' => (string) $badge,
                'badge_count' => (string) $badge,
                'muted' => $muted ? '1' : '0',
            ],
            'android' => [
                'priority' => 'high',
            ],
            'apns' => [
                'payload' => [
                    'aps' => [
                        'badge' => $badge,
                    ],
                ],
            ],
        ];

        if (! $muted) {
            $payload['notification'] = [
                'title' => $title,
                'body' => $body,
            ];

            $payload['android']['notification'] = [
                'channel_id' => 'messages',
                'sound' => 'default',
                'notification_count' => $badge,
            ];

            $payload['apns']['payload']['aps']['sound'] = 'default';
        } else {
            $payload['apns']['payload']['aps']['content-available'] = 1;
        }

        if ($device->platform !== 'android') {
            unset($payload['android']);
        }

        if ($device->platform !== 'ios') {
            unset($payload['apns']);
        }

        return $payload;
    }

    private function title(Message $message): string
    {
        if ($message->conversation->type === 'direct') {
            return (string) ($message->sender?->name ?? $message->sender?->email ?? 'New message');
        }

        return (string) ($message->conversation->title ?: 'New message');
    }

    private function body(Message $message): string
    {
        $preview = mb_strimwidth(trim((string) $message->body), 0, 120, '...');

        if ($preview === '') {
            $preview = match ($message->type) {
                'image' => 'Photo',
                'file' => 'File',
                'voice' => 'Voice message',
                default => 'New message',
            };
        }

        if ($message->conversation->type === 'direct') {
            return $preview;
        }

        $sender = (string) ($message->sender?->name ?? $message->sender?->email ?? 'Someone');

        return $sender.': '.$preview;
    }
}
