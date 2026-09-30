<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('transport', 32);
            $table->string('auth_method', 32);
            $table->foreignUuid('shared_device_id')->nullable()->constrained('shared_devices')->cascadeOnDelete();
            $table->unsignedBigInteger('device_generation')->nullable();
            $table->foreignId('personal_access_token_id')->nullable()->unique()->constrained('personal_access_tokens')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('last_user_activity_at')->nullable();
            $table->timestamp('password_confirmed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['shared_device_id', 'revoked_at']);
            $table->index(['expires_at', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
