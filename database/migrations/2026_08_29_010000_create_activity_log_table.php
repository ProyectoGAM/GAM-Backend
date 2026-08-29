<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();
            $table->uuid('operation_id');
            $table->uuid('trace_id')->nullable();
            $table->string('source', 50)->default('application');
            $table->unsignedBigInteger('up_id')->nullable();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index('log_name', 'activity_log_name_index');
            $table->index('event', 'activity_log_event_index');
            $table->index('operation_id', 'activity_log_operation_index');
            $table->index('trace_id', 'activity_log_trace_index');
            $table->index('source', 'activity_log_source_index');
            $table->index('up_id', 'activity_log_up_index');
            $table->index(
                ['subject_type', 'subject_id', 'created_at', 'id'],
                'activity_log_subject_history_index',
            );
            $table->index(
                ['causer_type', 'causer_id', 'created_at'],
                'activity_log_causer_history_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
