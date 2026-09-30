<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('driver_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained('production_units')->restrictOnDelete();
            $table->string('vehicle_reference', 120)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('loaded_quantity')->default(0);
            $table->unsignedInteger('returned_quantity')->default(0);
            $table->uuid('start_idempotency_key')->unique();
            $table->string('start_request_hash', 64);
            $table->uuid('close_idempotency_key')->nullable()->unique();
            $table->string('close_request_hash', 64)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('closed_at')->nullable();
            $table->text('close_notes')->nullable();
            $table->timestampsTz();

            $table->index(['driver_id', 'status']);
            $table->index(['status', 'started_at']);
        });

        Schema::create('delivery_loads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->unsignedInteger('quantity');
            $table->string('type', 40)->default('initial');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['delivery_id', 'created_at']);
        });

        Schema::create('delivery_stops', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('client_reference', 80);
            $table->string('client_name', 160);
            $table->string('address', 240);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('sequence');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('delivered_quantity')->default(0);
            $table->string('visit_reason', 240)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->string('request_hash', 64)->nullable();
            $table->timestampTz('visited_at')->nullable();
            $table->timestampsTz();

            $table->unique(['delivery_id', 'client_reference']);
            $table->index(['delivery_id', 'status']);
        });

        Schema::create('delivery_location_points', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->uuid('client_event_id');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->decimal('speed', 8, 2)->nullable();
            $table->string('request_hash', 64);
            $table->timestampTz('captured_at');
            $table->timestampTz('received_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['delivery_id', 'client_event_id']);
            $table->index(['delivery_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_location_points');
        Schema::dropIfExists('delivery_stops');
        Schema::dropIfExists('delivery_loads');
        Schema::dropIfExists('deliveries');
    }
};
