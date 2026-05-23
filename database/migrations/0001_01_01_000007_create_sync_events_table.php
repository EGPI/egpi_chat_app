<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();

            // The user/device should sync this event.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->nullable()
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('message_id')
                ->nullable()
                ->constrained('messages')
                ->cascadeOnDelete();

            // Examples:
            // conversation.created
            // conversation.updated
            // participant.added
            // participant.removed
            // message.created
            // message.edited
            // message.deleted
            // message.delivered
            // message.read
            $table->string('event_type', 80)->index();

            // Store the exact data the client needs to sync.
            $table->json('payload')->nullable();

            $table->timestampTz('occurred_at')->useCurrent();

            $table->timestampsTz();

            // Main sync query:
            // "give me all events for user X after event id Y"
            $table->index(['user_id', 'id']);

            $table->index(['user_id', 'occurred_at']);
            $table->index(['conversation_id', 'id']);
            $table->index(['message_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};