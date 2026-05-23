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
        Schema::create('user_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Generated/stored by the mobile app per device install.
            $table->uuid('device_uuid');

            // Examples: ios, android, web
            $table->string('platform', 30)->index();

            // FCM token can be long.
            $table->text('fcm_token');

            $table->string('device_name')->nullable();
            $table->string('app_version', 50)->nullable();
            $table->string('os_version', 80)->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->unique(['user_id', 'device_uuid']);

            // PostgreSQL supports unique indexes on text columns.
            $table->unique('fcm_token');

            $table->index(['user_id', 'is_active']);
            $table->index(['user_id', 'last_seen_at']);
            $table->index(['platform', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_devices');
    }
};