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
        Schema::create('calls', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('conversations')
                ->cascadeOnDelete();

            $table->foreignId('caller_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('callee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('type', 30)->default('audio');
            $table->string('status', 30)->index();

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('ended_at')->nullable();

            $table->unsignedInteger('duration_seconds')->default(0);

            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['caller_id', 'status']);
            $table->index(['callee_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('call_id')
                ->constrained('calls')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('role', 30);
            $table->string('status', 30);

            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();

            $table->timestampsTz();

            $table->unique(['call_id', 'role']);

            $table->index(['call_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_participants');
        Schema::dropIfExists('calls');
    }
};
