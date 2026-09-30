<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('published_version')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE plan_templates ADD CONSTRAINT plan_templates_state_check CHECK (status IN ('active', 'retired') AND current_version > 0 AND (published_version IS NULL OR published_version <= current_version))");

        Schema::create('plan_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_template_id')->constrained('plan_templates')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->uuid('operation_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['plan_template_id', 'number']);
        });
        DB::statement("ALTER TABLE plan_template_versions ADD CONSTRAINT plan_template_versions_state_check CHECK (status IN ('draft', 'published', 'retired') AND number > 0)");

        Schema::create('plan_template_activities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('plan_template_version_id')->constrained('plan_template_versions')->restrictOnDelete();
            $this->activityColumns($table);
            $table->unique(['plan_template_version_id', 'sort_order']);
        });
        $this->activityConstraint('plan_template_activities');

        Schema::create('flock_plans', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->unique()->constrained('flocks')->restrictOnDelete();
            $table->foreignId('plan_template_version_id')->nullable()->constrained('plan_template_versions')->restrictOnDelete();
            $table->foreignId('source_flock_id')->nullable()->constrained('flocks')->restrictOnDelete();
            $table->date('baseline_date');
            $table->unsignedInteger('current_revision')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE flock_plans ADD CONSTRAINT flock_plans_revision_check CHECK (current_revision > 0)');

        Schema::create('flock_plan_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_plan_id')->constrained('flock_plans')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->uuid('operation_id');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['flock_plan_id', 'number']);
        });
        DB::statement('ALTER TABLE flock_plan_revisions ADD CONSTRAINT flock_plan_revisions_number_check CHECK (number > 0)');

        Schema::create('flock_plan_activities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_plan_revision_id')->constrained('flock_plan_revisions')->restrictOnDelete();
            $table->foreignId('source_template_activity_id')->nullable()->constrained('plan_template_activities')->restrictOnDelete();
            $table->foreignId('copied_from_activity_id')->nullable()->constrained('flock_plan_activities')->restrictOnDelete();
            $this->activityColumns($table);
            $table->unique(['flock_plan_revision_id', 'sort_order']);
        });
        $this->activityConstraint('flock_plan_activities');

        Schema::table('weighings', function (Blueprint $table): void {
            $table->foreignId('flock_plan_activity_id')->nullable()->constrained('flock_plan_activities')->restrictOnDelete();
            $table->uuid('operation_id')->nullable()->index();
        });
        Schema::table('egg_collections', function (Blueprint $table): void {
            $table->foreignId('flock_plan_activity_id')->nullable()->constrained('flock_plan_activities')->restrictOnDelete();
            $table->uuid('operation_id')->nullable()->index();
        });
        Schema::table('mortality_records', function (Blueprint $table): void {
            $table->foreignId('flock_plan_activity_id')->nullable()->constrained('flock_plan_activities')->restrictOnDelete();
            $table->uuid('operation_id')->nullable()->index();
        });
        Schema::table('flock_movements', function (Blueprint $table): void {
            $table->foreignId('flock_plan_activity_id')->nullable()->constrained('flock_plan_activities')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mortality_records', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flock_plan_activity_id');
            $table->dropColumn('operation_id');
        });
        Schema::table('egg_collections', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flock_plan_activity_id');
            $table->dropColumn('operation_id');
        });
        Schema::table('flock_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flock_plan_activity_id');
        });
        Schema::table('weighings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('flock_plan_activity_id');
            $table->dropColumn('operation_id');
        });
        Schema::dropIfExists('flock_plan_activities');
        Schema::dropIfExists('flock_plan_revisions');
        Schema::dropIfExists('flock_plans');
        Schema::dropIfExists('plan_template_activities');
        Schema::dropIfExists('plan_template_versions');
        Schema::dropIfExists('plan_templates');
    }

    private function activityColumns(Blueprint $table): void
    {
        $table->unsignedInteger('sort_order');
        $table->string('type', 40);
        $table->string('title', 200);
        $table->string('timing_kind', 30);
        $table->unsignedInteger('start_day')->nullable();
        $table->unsignedInteger('end_day')->nullable();
        $table->unsignedInteger('start_week')->nullable();
        $table->unsignedInteger('end_week')->nullable();
        $table->unsignedInteger('interval_days')->nullable();
        $table->boolean('conditional')->default(false);
        $table->text('condition')->nullable();
        $table->text('notes')->nullable();
        $table->string('catalog_type', 30)->nullable();
        $table->unsignedBigInteger('catalog_id')->nullable();
        $table->jsonb('catalog_snapshot')->nullable();
        $table->timestampsTz();
    }

    private function activityConstraint(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_values_check CHECK (type IN ('vaccination', 'medication', 'ration_change', 'weighing', 'manual_practice', 'flock_movement', 'egg_collection', 'mortality') AND timing_kind IN ('day', 'week', 'week_range', 'day_recurrence', 'unscheduled') AND (catalog_type IS NULL OR catalog_type IN ('vaccine', 'medicine', 'product')) AND ((catalog_type IS NULL AND catalog_id IS NULL) OR (catalog_type IS NOT NULL AND catalog_id IS NOT NULL)) AND (interval_days IS NULL OR interval_days > 0))");
    }
};
