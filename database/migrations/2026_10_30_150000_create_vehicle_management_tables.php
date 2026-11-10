<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('plate_normalized', 16)->unique();
            $table->string('plate', 24);
            $table->string('make', 80);
            $table->string('model', 100);
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('admitted_at')->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->string('status_reason', 500)->nullable();
            $table->decimal('capacity_kg', 10, 2)->nullable();
            $table->foreignId('habitual_driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('vehicle_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->string('reason', 500);
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'occurred_at']);
        });

        Schema::create('vehicle_usages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->unique()->constrained('deliveries')->restrictOnDelete();
            $table->string('active_vehicle_key')->nullable()->unique();
            $table->string('purpose', 120);
            $table->text('notes')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable()->index();
            $table->decimal('start_odometer_km', 12, 1)->nullable();
            $table->decimal('end_odometer_km', 12, 1)->nullable();
            $table->json('vehicle_snapshot');
            $table->string('driver_snapshot', 160)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'started_at']);
        });

        Schema::create('vehicle_meter_readings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('usage_id')->nullable()->constrained('vehicle_usages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('corrects_reading_id')->nullable()->constrained('vehicle_meter_readings')->restrictOnDelete();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->string('meter_type', 24)->default('odometer');
            $table->string('event_type', 24)->default('reading');
            $table->string('event_reason', 500)->nullable();
            $table->unsignedInteger('counter_epoch')->default(0);
            $table->decimal('value', 12, 1);
            $table->timestampTz('recorded_at');
            $table->timestampTz('received_at');
            $table->string('source', 24)->default('manual');
            $table->string('correction_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'meter_type', 'recorded_at']);
        });

        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 60);
            $table->string('title', 160);
            $table->date('expires_at')->nullable()->index();
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestampsTz();
        });

        Schema::create('vehicle_fuel_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('usage_id')->nullable()->constrained('vehicle_usages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->timestampTz('occurred_at');
            $table->decimal('liters', 10, 3);
            $table->string('fuel_type', 40);
            $table->decimal('amount', 14, 3);
            $table->char('currency', 3)->default('UYU');
            $table->string('supplier', 160)->nullable();
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->string('receipt_original_name', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['vehicle_id', 'occurred_at']);
        });

        Schema::create('vehicle_maintenance_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->string('task', 160);
            $table->unsignedInteger('interval_days')->nullable();
            $table->decimal('interval_km', 12, 1)->nullable();
            $table->date('last_completed_at')->nullable();
            $table->decimal('last_odometer_km', 12, 1)->nullable();
            $table->date('next_due_at')->nullable()->index();
            $table->decimal('next_due_odometer_km', 12, 1)->nullable();
            $table->unsignedInteger('next_due_odometer_epoch')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['vehicle_id', 'active']);
        });

        Schema::create('vehicle_maintenances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 24);
            $table->string('status', 24)->default('planned')->index();
            $table->boolean('immobilizes_vehicle')->default(false);
            $table->string('workshop', 160)->nullable();
            $table->string('description', 500);
            $table->text('notes')->nullable();
            $table->dateTimeTz('planned_at')->nullable();
            $table->dateTimeTz('started_at')->nullable();
            $table->dateTimeTz('completed_at')->nullable()->index();
            $table->decimal('odometer_km', 12, 1)->nullable();
            $table->decimal('cost_amount', 14, 3)->default(0);
            $table->char('cost_currency', 3)->default('UYU');
            $table->string('idempotency_key', 120)->unique();
            $table->char('request_hash', 64);
            $table->string('cancellation_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['vehicle_id', 'status']);
        });

        Schema::create('vehicle_maintenance_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('maintenance_id')->constrained('vehicle_maintenances')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('vehicle_maintenance_plans')->nullOnDelete();
            $table->string('task', 160);
            $table->boolean('completed')->default(false);
            $table->text('notes')->nullable();
            $table->timestampsTz();
            $table->index(['maintenance_id', 'completed']);
        });

        Schema::create('vehicle_maintenance_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('maintenance_id')->constrained('vehicle_maintenances')->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestampsTz();
            $table->index(['maintenance_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenance_documents');
        Schema::dropIfExists('vehicle_maintenance_items');
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('vehicle_maintenance_plans');
        Schema::dropIfExists('vehicle_fuel_entries');
        Schema::dropIfExists('vehicle_documents');
        Schema::dropIfExists('vehicle_meter_readings');
        Schema::dropIfExists('vehicle_usages');
        Schema::dropIfExists('vehicle_status_changes');
        Schema::dropIfExists('vehicles');
    }
};
