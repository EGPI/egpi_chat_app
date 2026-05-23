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
        Schema::create('message_receipts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('message_id')
                ->constrained('messages')
                ->cascadeOnDelete();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();

            $table->timestampsTz();

            // One receipt row per message per user.
            $table->unique(['message_id', 'user_id']);

            $table->index(['conversation_id', 'user_id', 'read_at']);
            $table->index(['conversation_id', 'user_id', 'delivered_at']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'delivered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('message_receipts');
    }
};