<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_device_pairing_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('code_hash', 64)->unique();
            $table->string('name', 100);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->foreignUuid('shared_device_id')->nullable()->constrained('shared_devices')->nullOnDelete();
            $table->timestamps();

            $table->index(['expires_at', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_device_pairing_codes');
    }
};
