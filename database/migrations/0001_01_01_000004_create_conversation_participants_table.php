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
        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Examples: member, admin, owner
            $table->string('role', 30)->default('member');

            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampTz('left_at')->nullable();

            // User-level conversation settings
            $table->boolean('is_muted')->default(false);
            $table->timestampTz('muted_until')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->timestampTz('archived_at')->nullable();

            // Lightweight read tracking.
            // Detailed read/delivery state is still in message_receipts.
            $table->timestampTz('last_read_at')->nullable();

            $table->timestampsTz();

            $table->unique(['conversation_id', 'user_id']);

            $table->index(['user_id', 'left_at']);
            $table->index(['conversation_id', 'left_at']);
            $table->index(['user_id', 'is_pinned']);
            $table->index(['user_id', 'archived_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};