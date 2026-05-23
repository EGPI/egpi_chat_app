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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // Examples: direct, group, support, system
            $table->string('type', 30)->index();

            // Mainly for group chats. Direct chats may keep this null.
            $table->string('title')->nullable();
            $table->string('avatar_url')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Flexible future data without changing schema every time.
            $table->json('metadata')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['type', 'created_at']);
            $table->index(['created_by', 'created_at']);
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};