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
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('last_message_id')
                ->nullable()
                ->after('metadata')
                ->constrained('messages')
                ->nullOnDelete();

            $table->foreignId('last_message_sender_id')
                ->nullable()
                ->after('last_message_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('last_message_preview', 500)
                ->nullable()
                ->after('last_message_sender_id');

            $table->timestampTz('last_message_at')
                ->nullable()
                ->after('last_message_preview');

            $table->index(['last_message_at', 'id']);
        });

        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->unsignedInteger('unread_count')
                ->default(0)
                ->after('last_read_at');

            $table->foreignId('last_read_message_id')
                ->nullable()
                ->after('unread_count')
                ->constrained('messages')
                ->nullOnDelete();

            $table->index(['user_id', 'unread_count']);
            $table->index(['user_id', 'last_read_message_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropForeign(['last_read_message_id']);
            $table->dropIndex(['user_id', 'unread_count']);
            $table->dropIndex(['user_id', 'last_read_message_id']);

            $table->dropColumn([
                'unread_count',
                'last_read_message_id',
            ]);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['last_message_id']);
            $table->dropForeign(['last_message_sender_id']);
            $table->dropIndex(['last_message_at', 'id']);

            $table->dropColumn([
                'last_message_id',
                'last_message_sender_id',
                'last_message_preview',
                'last_message_at',
            ]);
        });
    }
};