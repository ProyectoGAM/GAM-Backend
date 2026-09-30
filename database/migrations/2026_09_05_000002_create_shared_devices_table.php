<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->char('credential_hash', 64)->unique();
            $table->timestamp('credential_expires_at');
            $table->foreignId('enrolled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('enrolled_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedBigInteger('session_generation')->default(0);
            $table->timestamps();

            $table->index(['revoked_at', 'credential_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_devices');
    }
};
