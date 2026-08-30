<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->char('idempotency_key_hash', 64);
            $table->char('payload_hash', 64);
            $table->string('source_key', 100);
            $table->string('definition_version', 40);
            $table->jsonb('query');
            $table->string('format', 10);
            $table->string('status', 20)->default('pending');
            $table->string('disk', 50)->default('local');
            $table->text('path')->nullable();
            $table->string('file_name', 255);
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key_hash']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('xlsx', 'pdf'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'expired'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
