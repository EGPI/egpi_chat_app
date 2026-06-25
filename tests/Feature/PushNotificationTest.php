<?php

namespace Tests\Feature;

use App\Jobs\SendMessagePushNotification;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\FirebaseCloudMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_revoke_device(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        Sanctum::actingAs($user);

        $this->postJson('/api/devices', [
            'device_uuid' => '7d9b94de-8b65-4f6d-9d02-ec8f92895a6b',
            'platform' => 'android',
            'fcm_token' => 'fcm-token-1',
            'device_name' => 'Pixel',
            'app_version' => '1.0.0',
            'os_version' => 'Android 15',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Device registered successfully.')
            ->assertJsonPath('data.platform', 'android')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_uuid' => '7d9b94de-8b65-4f6d-9d02-ec8f92895a6b',
            'fcm_token' => 'fcm-token-1',
            'is_active' => true,
        ]);

        $this->deleteJson('/api/devices/7d9b94de-8b65-4f6d-9d02-ec8f92895a6b')
            ->assertOk()
            ->assertJsonPath('message', 'Device revoked successfully.');

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_uuid' => '7d9b94de-8b65-4f6d-9d02-ec8f92895a6b',
            'is_active' => false,
        ]);
    }

    public function test_sending_message_queues_push_for_recipient_only_once(): void
    {
        Queue::fake();

        [$sender, $recipient, $conversation] = $this->directConversation();

        Sanctum::actingAs($sender);

        $payload = [
            'client_message_id' => '42a8631d-d6e2-4326-a1b6-e9c3dd573b2d',
            'body' => 'Hello from the backend',
        ];

        $this->postJson("/api/conversations/{$conversation->id}/messages", $payload)
            ->assertCreated()
            ->assertJsonPath('duplicate', false);

        Queue::assertPushed(SendMessagePushNotification::class, function (SendMessagePushNotification $job) use ($recipient) {
            return $job->recipientUserId === $recipient->id;
        });

        $this->postJson("/api/conversations/{$conversation->id}/messages", $payload)
            ->assertOk()
            ->assertJsonPath('duplicate', true);

        Queue::assertPushed(SendMessagePushNotification::class, 1);
    }

    public function test_group_message_queues_push_for_all_non_sender_participants(): void
    {
        Queue::fake();

        $sender = User::factory()->create(['is_active' => true]);
        $recipientA = User::factory()->create(['is_active' => true]);
        $recipientB = User::factory()->create(['is_active' => true]);

        $conversation = Conversation::query()->create([
            'type' => 'group',
            'title' => 'Sales Team',
            'created_by' => $sender->id,
        ]);

        $this->participant($conversation, $sender);
        $this->participant($conversation, $recipientA);
        $this->participant($conversation, $recipientB);

        Sanctum::actingAs($sender);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'client_message_id' => '54df8669-2f1b-4320-a93d-fd0c1827b616',
            'body' => 'Quarterly report is ready',
        ])->assertCreated();

        Queue::assertPushed(SendMessagePushNotification::class, 2);
        Queue::assertPushed(SendMessagePushNotification::class, fn (SendMessagePushNotification $job) => $job->recipientUserId === $recipientA->id);
        Queue::assertPushed(SendMessagePushNotification::class, fn (SendMessagePushNotification $job) => $job->recipientUserId === $recipientB->id);
        Queue::assertNotPushed(SendMessagePushNotification::class, fn (SendMessagePushNotification $job) => $job->recipientUserId === $sender->id);
    }

    public function test_muted_conversation_push_is_data_only(): void
    {
        [$sender, $recipient, $conversation] = $this->directConversation();

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->update(['is_muted' => true]);

        $device = UserDevice::query()->create([
            'user_id' => $recipient->id,
            'device_uuid' => '87031dfb-3bfd-4c50-ae8e-50e670539ad1',
            'platform' => 'ios',
            'fcm_token' => 'muted-token',
            'is_active' => true,
        ]);

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'client_message_id' => '9de8b488-0af2-48e8-aea2-33a776f7ff46',
            'type' => 'text',
            'body' => 'Muted hello',
            'server_received_at' => now(),
            'sent_at' => now(),
        ]);

        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $recipient->id)
            ->update(['unread_count' => 3]);

        $fakeFcm = new class extends FirebaseCloudMessagingService
        {
            public array $messages = [];

            public function send(UserDevice $device, array $message): bool
            {
                $this->messages[] = compact('device', 'message');

                return true;
            }
        };

        (new SendMessagePushNotification($message->id, $recipient->id))->handle($fakeFcm);

        $this->assertCount(1, $fakeFcm->messages);
        $this->assertSame($device->id, $fakeFcm->messages[0]['device']->id);
        $this->assertArrayNotHasKey('notification', $fakeFcm->messages[0]['message']);
        $this->assertSame('1', $fakeFcm->messages[0]['message']['data']['muted']);
        $this->assertSame(3, $fakeFcm->messages[0]['message']['apns']['payload']['aps']['badge']);
    }

    private function directConversation(): array
    {
        $sender = User::factory()->create(['name' => 'Ahmed', 'is_active' => true]);
        $recipient = User::factory()->create(['name' => 'Sara', 'is_active' => true]);

        $conversation = Conversation::query()->create([
            'type' => 'direct',
            'title' => null,
            'created_by' => $sender->id,
        ]);

        $this->participant($conversation, $sender);
        $this->participant($conversation, $recipient);

        return [$sender, $recipient, $conversation];
    }

    private function participant(Conversation $conversation, User $user): ConversationParticipant
    {
        return ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);
    }
}
