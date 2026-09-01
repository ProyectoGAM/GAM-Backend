<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['breeds', 'mortality_categories'] as $tableName) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('normalized_name', 120)->unique();
                $table->string('status', 20)->default('active');
                $table->unsignedInteger('version')->default(1);
                $table->timestampsTz();
            });
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_status_check CHECK (status IN ('active', 'inactive'))");
            DB::statement("ALTER TABLE {$tableName} ADD CONSTRAINT {$tableName}_version_check CHECK (version > 0)");
        }

        Schema::create('flocks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 60)->unique();
            $table->foreignId('breed_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('origin', 255)->nullable();
            $table->string('supplier_name', 255)->nullable();
            $table->foreignId('poultry_house_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('initial_quantity');
            $table->unsignedInteger('current_quantity');
            $table->date('entry_date');
            $table->timestampTz('established_at');
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->string('finalization_reason', 500)->nullable();
            $table->timestampsTz();
            $table->index(['poultry_house_id', 'status']);
            $table->index(['production_unit_id', 'status', 'id']);
            $table->index(['entry_date', 'id']);
        });
        DB::statement('ALTER TABLE flocks ADD CONSTRAINT flocks_quantities_check CHECK (initial_quantity > 0 AND current_quantity >= 0 AND version > 0)');
        DB::statement("ALTER TABLE flocks ADD CONSTRAINT flocks_status_check CHECK (status IN ('active', 'quarantined', 'finished') AND (status <> 'finished' OR (current_quantity = 0 AND finalized_at IS NOT NULL)))");
        DB::statement('ALTER TABLE flocks ADD CONSTRAINT flocks_origin_check CHECK (supplier_id IS NOT NULL OR (origin IS NOT NULL AND length(trim(origin)) > 0))');

        Schema::create('flock_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->string('command', 80);
            $table->char('request_hash', 64);
            $table->jsonb('result');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['created_by', 'idempotency_key']);
        });

        Schema::create('flock_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->uuid('operation_id')->index();
            $table->string('type', 40);
            $table->foreignId('source_flock_id')->nullable()->constrained('flocks')->restrictOnDelete();
            $table->foreignId('destination_flock_id')->nullable()->constrained('flocks')->restrictOnDelete();
            $table->foreignId('source_poultry_house_id')->nullable()->constrained('poultry_houses')->restrictOnDelete();
            $table->foreignId('destination_poultry_house_id')->nullable()->constrained('poultry_houses')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->jsonb('before');
            $table->jsonb('after');
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reverses_movement_id')->nullable()->unique()->constrained('flock_movements')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['source_flock_id', 'occurred_at', 'id']);
            $table->index(['destination_flock_id', 'occurred_at', 'id']);
        });
        DB::statement('ALTER TABLE flock_movements ADD CONSTRAINT flock_movements_quantity_check CHECK (quantity >= 0)');
        DB::statement("ALTER TABLE flock_movements ADD CONSTRAINT flock_movements_type_check CHECK (type IN ('admission', 'partial_new', 'partial_existing', 'total', 'departure', 'mortality', 'mortality_correction', 'redistribution_reversal'))");

        Schema::create('mortality_records', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained()->restrictOnDelete();
            $table->foreignId('poultry_house_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('mortality_category_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestampTz('occurred_at');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['poultry_house_id', 'occurred_at', 'id']);
        });
        DB::statement("ALTER TABLE mortality_records ADD CONSTRAINT mortality_records_values_check CHECK (quantity > 0 AND version > 0 AND status IN ('recorded', 'cancelled'))");

        Schema::create('egg_collections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained()->restrictOnDelete();
            $table->foreignId('poultry_house_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            // Alias heredados; las clasificaciones nuevas viven en egg_collection_lines.
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_movement_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('collected_quantity');
            // Alias histórico conservado para lecturas de instalaciones previas del módulo de Lotes.
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('discarded_quantity')->default(0);
            $table->string('discard_reason', 500)->nullable();
            $table->timestampTz('occurred_at');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['poultry_house_id', 'occurred_at', 'id']);
        });
        DB::statement("ALTER TABLE egg_collections ADD CONSTRAINT egg_collections_values_check CHECK (collected_quantity > 0 AND discarded_quantity >= 0 AND discarded_quantity <= collected_quantity AND version > 0 AND status IN ('recorded', 'cancelled'))");
        DB::statement('ALTER TABLE egg_collections ADD CONSTRAINT egg_collections_discard_reason_check CHECK (discarded_quantity = 0 OR (discard_reason IS NOT NULL AND length(trim(discard_reason)) > 0))');

        Schema::create('egg_collection_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('egg_collection_id')->constrained('egg_collections')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestampsTz();
            $table->unique(['egg_collection_id', 'product_id', 'stock_location_id']);
            $table->index(['product_id', 'stock_location_id', 'created_at']);
        });
        DB::statement('ALTER TABLE egg_collection_lines ADD CONSTRAINT egg_collection_lines_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_collection_lines');
        Schema::dropIfExists('egg_collections');
        Schema::dropIfExists('mortality_records');
        Schema::dropIfExists('flock_movements');
        Schema::dropIfExists('flock_operations');
        Schema::dropIfExists('flocks');
        Schema::dropIfExists('mortality_categories');
        Schema::dropIfExists('breeds');
    }
};
