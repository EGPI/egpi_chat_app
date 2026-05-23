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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('sender_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Important for mobile retry/idempotency.
            // Flutter can generate this before sending.
            $table->uuid('client_message_id')->nullable();

            // Examples: text, image, file, voice, system
            $table->string('type', 30)->default('text')->index();

            $table->text('body')->nullable();

            // For attachments, mentions, system payloads, reply info, etc.
            $table->json('payload')->nullable();

            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->constrained('messages')
                ->nullOnDelete();

            $table->timestampTz('sent_at')->useCurrent();
            $table->timestampTz('edited_at')->nullable();
            $table->softDeletesTz();
            $table->timestampsTz();

            // Prevent duplicate messages when mobile retries the same send.
            $table->unique([
                'conversation_id',
                'sender_id',
                'client_message_id',
            ], 'messages_conversation_sender_client_unique');

            // Main pagination/query index for chat history.
            $table->index(['conversation_id', 'created_at', 'id']);

            $table->index(['conversation_id', 'sent_at', 'id']);
            $table->index(['conversation_id', 'deleted_at']);
            $table->index(['sender_id', 'created_at']);
            $table->index('reply_to_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};