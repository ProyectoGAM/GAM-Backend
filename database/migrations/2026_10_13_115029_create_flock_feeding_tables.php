<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE flock_operations ALTER COLUMN created_by DROP NOT NULL');
        DB::statement("ALTER TABLE flock_operations ADD CONSTRAINT flock_operation_automatic_actor CHECK (created_by IS NOT NULL OR command = 'flock.feeding.daily')");
        Schema::table('ration_changes', function (Blueprint $table): void {
            $table->foreignId('feed_recipe_id')->nullable()->constrained()->restrictOnDelete();
            $table->jsonb('feed_recipe_snapshot')->nullable();
            $table->bigInteger('daily_quantity_grams')->nullable();
        });
        foreach (['plan_template_activities', 'flock_plan_activities'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('feed_recipe_id')->nullable()->constrained()->restrictOnDelete();
                $table->bigInteger('grams_per_bird')->nullable();
            });
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT {$name}_values_check");
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_values_check CHECK (type IN ('vaccination', 'medication', 'ration_change', 'weighing', 'manual_practice', 'flock_movement', 'egg_collection', 'mortality') AND timing_kind IN ('day', 'week', 'week_range', 'day_recurrence', 'unscheduled') AND (catalog_type IS NULL OR catalog_type IN ('vaccine', 'medicine', 'product', 'feed_recipe')) AND ((catalog_type IS NULL AND catalog_id IS NULL) OR (catalog_type IS NOT NULL AND catalog_id IS NOT NULL)) AND (interval_days IS NULL OR interval_days > 0) AND (grams_per_bird IS NULL OR grams_per_bird BETWEEN 1 AND 999999999999))");
            $parent = $name === 'plan_template_activities' ? 'plan_template_version_id' : 'flock_plan_revision_id';
            DB::statement("CREATE UNIQUE INDEX {$name}_initial_feeding ON {$name} ({$parent}) WHERE type = 'ration_change' AND (start_day = 1 OR start_week = 1)");
        }

        Schema::create('flock_feeding_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flock_id')->constrained()->restrictOnDelete();
            $table->foreignId('flock_plan_revision_id')->constrained()->restrictOnDelete();
            $table->uuid('operation_id');
            $table->date('effective_date');
            $table->date('baseline_date');
            $table->string('mode', 20);
            $table->foreignId('feed_recipe_id')->constrained()->restrictOnDelete();
            $table->bigInteger('grams_per_bird');
            $table->bigInteger('daily_quantity_grams')->nullable();
            $table->integer('anchor_day');
            $table->jsonb('stage_snapshot');
            $table->jsonb('stages');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['flock_id', 'operation_id']);
            $table->index(['flock_id', 'effective_date', 'id'], 'flock_feeding_effective_history');
        });
        DB::statement("ALTER TABLE flock_feeding_changes ADD CONSTRAINT flock_feeding_values CHECK (mode IN ('automatic', 'manual') AND grams_per_bird BETWEEN 1 AND 999999999999 AND anchor_day > 0 AND ((mode = 'manual' AND daily_quantity_grams BETWEEN 1 AND 999999999999) OR (mode = 'automatic' AND daily_quantity_grams IS NULL)))");

        Schema::create('feed_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flock_id')->constrained()->restrictOnDelete();
            $table->foreignId('flock_feeding_change_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('feed_recipe_id')->constrained()->restrictOnDelete();
            $table->date('consumption_date');
            $table->string('mode', 20);
            $table->integer('age_days');
            $table->bigInteger('population');
            $table->bigInteger('grams_per_bird');
            $table->bigInteger('quantity_grams');
            $table->jsonb('stage_snapshot');
            $table->jsonb('census_snapshot');
            $table->uuid('operation_id')->unique();
            $table->timestampTz('created_at');
            $table->unique(['flock_id', 'consumption_date']);
        });
        DB::statement("ALTER TABLE feed_consumptions ADD CONSTRAINT feed_consumption_values CHECK (mode IN ('automatic', 'manual') AND quantity_grams BETWEEN 0 AND 999999999999 AND grams_per_bird BETWEEN 1 AND 999999999999 AND age_days > 0)");

        Schema::create('feed_consumption_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_consumption_id')->constrained()->restrictOnDelete();
            $table->foreignId('feed_recipe_id')->constrained()->restrictOnDelete();
            $table->bigInteger('quantity_grams');
            $table->text('reason');
            $table->jsonb('before_snapshot');
            $table->uuid('operation_id')->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at');
            $table->unique(['created_by', 'idempotency_key']);
            $table->index(['feed_consumption_id', 'id']);
        });
        DB::statement('ALTER TABLE feed_consumption_corrections ADD CONSTRAINT feed_correction_values CHECK (quantity_grams BETWEEN 0 AND 999999999999 AND length(trim(reason)) BETWEEN 1 AND 2000)');

        Schema::create('feed_consumption_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feed_consumption_id')->constrained()->restrictOnDelete();
            $table->foreignId('feed_consumption_correction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('feed_recipe_id')->constrained()->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained()->restrictOnDelete();
            $table->bigInteger('delta_grams');
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->uuid('operation_id');
            $table->timestampTz('created_at');
            $table->unique(['operation_id', 'feed_recipe_id']);
            $table->index(['feed_consumption_id', 'id']);
            $table->index(['production_unit_id', 'feed_recipe_id', 'id'], 'feed_consumption_stock_history');
        });
        DB::statement('ALTER TABLE feed_consumption_movements ADD CONSTRAINT feed_consumption_movement_values CHECK (delta_grams <> 0 AND balance_before BETWEEN -999999999999 AND 999999999999 AND balance_after BETWEEN -999999999999 AND 999999999999 AND balance_after = balance_before + delta_grams)');
    }

    public function down(): void
    {
        if (DB::table('flock_feeding_changes')->exists()
            || DB::table('plan_template_activities')->whereNotNull('feed_recipe_id')->exists()
            || DB::table('flock_plan_activities')->whereNotNull('feed_recipe_id')->exists()
            || DB::table('flock_operations')->whereNull('created_by')->exists()) {
            throw new RuntimeException('La alimentación ya contiene etapas o historia; requiere una migración correctiva para conservarlas.');
        }
        Schema::dropIfExists('feed_consumption_movements');
        Schema::dropIfExists('feed_consumption_corrections');
        Schema::dropIfExists('feed_consumptions');
        Schema::dropIfExists('flock_feeding_changes');
        Schema::table('ration_changes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('feed_recipe_id');
            $table->dropColumn(['feed_recipe_snapshot', 'daily_quantity_grams']);
        });
        foreach (['plan_template_activities', 'flock_plan_activities'] as $name) {
            DB::statement("DROP INDEX {$name}_initial_feeding");
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT {$name}_values_check");
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('feed_recipe_id');
                $table->dropColumn('grams_per_bird');
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_values_check CHECK (type IN ('vaccination', 'medication', 'ration_change', 'weighing', 'manual_practice', 'flock_movement', 'egg_collection', 'mortality') AND timing_kind IN ('day', 'week', 'week_range', 'day_recurrence', 'unscheduled') AND (catalog_type IS NULL OR catalog_type IN ('vaccine', 'medicine', 'product')) AND ((catalog_type IS NULL AND catalog_id IS NULL) OR (catalog_type IS NOT NULL AND catalog_id IS NOT NULL)) AND (interval_days IS NULL OR interval_days > 0))");
        }
        DB::statement('ALTER TABLE flock_operations DROP CONSTRAINT flock_operation_automatic_actor');
        DB::statement('ALTER TABLE flock_operations ALTER COLUMN created_by SET NOT NULL');
    }
};
