<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\SyncEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConversationMemberManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_details_returns_current_role_and_active_participants(): void
    {
        $owner = User::factory()->create(['name' => 'Ahmed', 'email' => 'ahmed@example.com', 'is_active' => true]);
        $member = User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com', 'is_active' => true]);
        $removed = User::factory()->create(['name' => 'Omar']);
        $conversation = $this->groupConversation($owner);

        $this->participant($conversation, $owner, 'owner');
        $this->participant($conversation, $member, 'member');
        $this->participant($conversation, $removed, 'member', leftAt: now());

        Sanctum::actingAs($member);

        $this->getJson("/api/conversations/{$conversation->id}/details")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.type', 'group')
            ->assertJsonPath('data.title', 'Sales Team')
            ->assertJsonPath('data.my_role', 'member')
            ->assertJsonCount(2, 'data.participants')
            ->assertJsonPath('data.participants.0.name', 'Ahmed')
            ->assertJsonPath('data.participants.0.role', 'owner')
            ->assertJsonPath('data.participants.1.email', 'sara@example.com');
    }

    public function test_owner_can_add_member_with_rich_events_system_message_and_preview(): void
    {
        $owner = User::factory()->create(['name' => 'Ahmed', 'is_active' => true]);
        $sara = User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com', 'is_active' => true]);
        $conversation = $this->groupConversation($owner);

        $this->participant($conversation, $owner, 'owner');

        Sanctum::actingAs($owner);

        $this->postJson("/api/conversations/{$conversation->id}/members", [
            'user_id' => $sara->id,
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Member added successfully.')
            ->assertJsonPath('data.conversation_id', $conversation->id)
            ->assertJsonPath('data.user_id', $sara->id)
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.email', 'sara@example.com')
            ->assertJsonPath('data.role', 'member');

        $message = Message::query()->where('conversation_id', $conversation->id)->firstOrFail();

        $this->assertSame('system', $message->type);
        $this->assertSame('Ahmed added Sara', $message->body);
        $this->assertSame('participant.added', $message->payload['system_type']);

        $conversation->refresh();
        $this->assertSame($message->id, $conversation->last_message_id);
        $this->assertSame('Ahmed added Sara', $conversation->last_message_preview);
        $this->assertSame($owner->id, $conversation->last_message_sender_id);

        $addedEvent = SyncEvent::query()
            ->where('event_type', 'participant.added')
            ->where('user_id', $sara->id)
            ->firstOrFail();

        $this->assertSame('Sara', $addedEvent->payload['user_name']);
        $this->assertSame('sara@example.com', $addedEvent->payload['user_email']);
        $this->assertSame('Ahmed', $addedEvent->payload['added_by_name']);

        $this->assertEqualsCanonicalizing(
            [$owner->id, $sara->id],
            SyncEvent::query()
                ->where('event_type', 'participant.added')
                ->pluck('user_id')
                ->all()
        );

        $this->assertTrue(SyncEvent::query()
            ->where('event_type', 'message.created')
            ->where('message_id', $message->id)
            ->where('user_id', $sara->id)
            ->exists());

        $this->assertTrue(SyncEvent::query()
            ->where('event_type', 'conversation.updated')
            ->where('message_id', $message->id)
            ->where('user_id', $sara->id)
            ->exists());

        Sanctum::actingAs($sara);

        $syncResponse = $this->getJson('/api/sync')
            ->assertOk()
            ->json('events');

        $this->assertSame([
            'participant.added',
            'message.created',
            'conversation.updated',
        ], collect($syncResponse)->pluck('event_type')->all());

        $this->assertSame(
            collect($syncResponse)->pluck('id')->all(),
            collect($syncResponse)->pluck('event_id')->all()
        );

        $this->assertSame($conversation->id, $syncResponse[0]['payload']['conversation_id']);
        $this->assertSame('Sara', $syncResponse[0]['payload']['user_name']);
        $this->assertSame($message->id, $syncResponse[1]['payload']['message_id']);
        $this->assertSame('participant.added', $syncResponse[1]['payload']['payload']['system_type']);
        $this->assertSame($message->id, $syncResponse[2]['payload']['last_message_id']);
        $this->assertSame('Ahmed added Sara', $syncResponse[2]['payload']['last_message_preview']);
    }

    public function test_remove_and_promote_return_user_details_and_create_system_messages(): void
    {
        $owner = User::factory()->create(['name' => 'Ahmed', 'is_active' => true]);
        $admin = User::factory()->create(['name' => 'Mona', 'is_active' => true]);
        $sara = User::factory()->create(['name' => 'Sara', 'is_active' => true]);
        $omar = User::factory()->create(['name' => 'Omar', 'is_active' => true]);
        $conversation = $this->groupConversation($owner);

        $this->participant($conversation, $owner, 'owner');
        $this->participant($conversation, $admin, 'admin');
        $this->participant($conversation, $sara, 'member');
        $this->participant($conversation, $omar, 'member');

        Sanctum::actingAs($owner);

        $this->postJson("/api/conversations/{$conversation->id}/admins/{$sara->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $sara->id)
            ->assertJsonPath('data.name', 'Sara')
            ->assertJsonPath('data.role', 'admin');

        $this->deleteJson("/api/conversations/{$conversation->id}/members/{$omar->id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $omar->id)
            ->assertJsonPath('data.name', 'Omar')
            ->assertJsonPath('data.role', 'member');

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'type' => 'system',
            'body' => 'Ahmed promoted Sara to admin',
        ]);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'type' => 'system',
            'body' => 'Ahmed removed Omar',
        ]);

        $this->assertTrue(SyncEvent::query()
            ->where('event_type', 'participant.role_changed')
            ->where('user_id', $admin->id)
            ->whereJsonContains('payload->changed_by_name', 'Ahmed')
            ->exists());

        $this->assertEqualsCanonicalizing(
            [$owner->id, $admin->id, $sara->id, $omar->id],
            SyncEvent::query()
                ->where('event_type', 'participant.role_changed')
                ->pluck('user_id')
                ->all()
        );

        $this->assertTrue(SyncEvent::query()
            ->where('event_type', 'participant.removed')
            ->where('user_id', $omar->id)
            ->whereJsonContains('payload->removed_by_name', 'Ahmed')
            ->exists());

        $this->assertEqualsCanonicalizing(
            [$owner->id, $admin->id, $sara->id, $omar->id],
            SyncEvent::query()
                ->where('event_type', 'participant.removed')
                ->pluck('user_id')
                ->all()
        );
    }

    public function test_permissions_block_members_direct_chats_and_admin_removing_owner(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $admin = User::factory()->create(['is_active' => true]);
        $member = User::factory()->create(['is_active' => true]);
        $target = User::factory()->create(['is_active' => true]);
        $group = $this->groupConversation($owner);

        $this->participant($group, $owner, 'owner');
        $this->participant($group, $admin, 'admin');
        $this->participant($group, $member, 'member');

        Sanctum::actingAs($member);

        $this->postJson("/api/conversations/{$group->id}/members", [
            'user_id' => $target->id,
        ])->assertForbidden();

        Sanctum::actingAs($admin);

        $this->deleteJson("/api/conversations/{$group->id}/members/{$owner->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Owner cannot be removed.');

        $direct = Conversation::query()->create([
            'type' => 'direct',
            'title' => null,
            'created_by' => $owner->id,
        ]);

        $this->participant($direct, $owner, 'member');
        $this->participant($direct, $target, 'member');

        Sanctum::actingAs($owner);

        $this->postJson("/api/conversations/{$direct->id}/members", [
            'user_id' => $member->id,
        ])->assertStatus(422);
    }

    private function groupConversation(User $owner): Conversation
    {
        return Conversation::query()->create([
            'type' => 'group',
            'title' => 'Sales Team',
            'created_by' => $owner->id,
        ]);
    }

    private function participant(
        Conversation $conversation,
        User $user,
        string $role,
        mixed $leftAt = null
    ): ConversationParticipant {
        return ConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => $role,
            'joined_at' => now(),
            'left_at' => $leftAt,
        ]);
    }
}
