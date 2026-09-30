<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicine_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medicine_id')->unique()->constrained('medicines')->restrictOnDelete();
            $table->bigInteger('on_hand_quantity')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE medicine_stock_balances ADD CONSTRAINT medicine_stock_balances_version_check CHECK (version > 0)');

        Schema::create('medicine_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('medicine_public_id_snapshot', 26);
            $table->string('medicine_name_snapshot', 160);
            $table->ulid('medicine_application_public_id')->nullable();
            $table->string('movement_type', 20);
            $table->bigInteger('quantity_delta');
            $table->bigInteger('balance_after');
            $table->string('reason', 500);
            $table->uuid('operation_id')->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['medicine_id', 'created_at', 'id']);
        });
        DB::statement("ALTER TABLE medicine_stock_movements ADD CONSTRAINT medicine_stock_movements_values_check CHECK (movement_type IN ('application', 'adjustment', 'correction') AND quantity_delta <> 0)");

        Schema::create('medicine_applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->ulid('plan_activity_id')->nullable();
            $table->string('plan_activity_title_snapshot', 200)->nullable();
            $table->foreignId('medicine_id')->constrained('medicines')->restrictOnDelete();
            $table->string('medicine_public_id_snapshot', 26);
            $table->string('medicine_name_snapshot', 160);
            $table->text('medicine_description_snapshot');
            $table->string('supplier_name_snapshot', 160);
            $table->timestampTz('occurred_at');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsible_name_snapshot', 255);
            $table->unsignedInteger('quantity');
            $table->string('reason', 500);
            $table->foreignId('stock_movement_id')->constrained('medicine_stock_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['flock_id', 'plan_activity_id']);
        });
        DB::statement('ALTER TABLE medicine_applications ADD CONSTRAINT medicine_applications_quantity_check CHECK (quantity > 0)');

        Schema::create('vaccination_applications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->ulid('plan_activity_id')->nullable();
            $table->string('plan_activity_title_snapshot', 200)->nullable();
            $table->foreignId('vaccine_id')->constrained('vaccines')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->jsonb('vaccine_snapshot');
            $table->jsonb('product_snapshot');
            $table->timestampTz('occurred_at');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsible_name_snapshot', 255);
            $table->text('notes')->nullable();
            $table->decimal('inventory_quantity', 18, 6)->nullable();
            $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $table->string('stock_location_name_snapshot', 160)->nullable();
            $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['flock_id', 'plan_activity_id']);
        });
        DB::statement('ALTER TABLE vaccination_applications ADD CONSTRAINT vaccination_applications_inventory_check CHECK ((inventory_quantity IS NULL AND stock_location_id IS NULL AND stock_location_name_snapshot IS NULL AND inventory_movement_id IS NULL) OR (inventory_quantity > 0 AND stock_location_id IS NOT NULL AND stock_location_name_snapshot IS NOT NULL AND inventory_movement_id IS NOT NULL))');

        Schema::create('ration_changes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->ulid('plan_activity_id')->nullable();
            $table->string('plan_activity_title_snapshot', 200)->nullable();
            $table->text('ration_description');
            $table->foreignId('finished_feed_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->jsonb('finished_feed_product_snapshot')->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsible_name_snapshot', 255);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['flock_id', 'plan_activity_id']);
        });
        DB::statement('ALTER TABLE ration_changes ADD CONSTRAINT ration_changes_product_snapshot_check CHECK ((finished_feed_product_id IS NULL AND finished_feed_product_snapshot IS NULL) OR (finished_feed_product_id IS NOT NULL AND finished_feed_product_snapshot IS NOT NULL))');

        Schema::create('manual_practices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->ulid('plan_activity_id')->nullable();
            $table->string('plan_activity_title_snapshot', 200)->nullable();
            $table->string('practice_type', 40);
            $table->string('practice_title_snapshot', 200);
            $table->timestampTz('occurred_at');
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->string('responsible_name_snapshot', 255);
            $table->text('notes');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['flock_id', 'plan_activity_id']);
        });
        DB::statement("ALTER TABLE manual_practices ADD CONSTRAINT manual_practices_type_check CHECK (practice_type IN ('beak_trimming', 'nest_placement', 'other'))");

        Schema::create('management_execution_corrections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->string('original_type', 40);
            $table->ulid('original_public_id');
            $table->uuid('original_operation_id');
            $table->string('correction_reason', 500);
            $table->jsonb('original_snapshot');
            $table->jsonb('compensation_snapshot')->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id')->unique();
            $table->timestampsTz();
            $table->unique(['original_type', 'original_public_id']);
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['flock_id', 'original_operation_id']);
        });
        DB::statement("ALTER TABLE management_execution_corrections ADD CONSTRAINT management_execution_corrections_type_check CHECK (original_type IN ('vaccination', 'medication', 'ration_change', 'manual_practice'))");

    }

    public function down(): void
    {
        Schema::dropIfExists('management_execution_corrections');
        Schema::dropIfExists('manual_practices');
        Schema::dropIfExists('ration_changes');
        Schema::dropIfExists('vaccination_applications');
        Schema::dropIfExists('medicine_applications');
        Schema::dropIfExists('medicine_stock_movements');
        Schema::dropIfExists('medicine_stock_balances');
    }
};
