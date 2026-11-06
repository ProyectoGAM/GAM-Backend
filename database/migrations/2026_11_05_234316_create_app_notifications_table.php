<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('production_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('type', 80);
            $table->string('source_key', 160);
            $table->string('title', 160);
            $table->text('message');
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 80)->nullable();
            $table->string('target_path')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('read_at')->nullable();
            $table->unique(['recipient_id', 'type', 'source_key']);
            $table->index(['recipient_id', 'occurred_at', 'id']);
            $table->index(['recipient_id', 'production_unit_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
