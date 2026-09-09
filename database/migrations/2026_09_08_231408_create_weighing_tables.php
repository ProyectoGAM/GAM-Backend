<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighing_reference_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('singleton_key')->default(true);
            $table->unsignedInteger('adult_from_week');
            $table->decimal('chick_min_weight_g', 14, 1);
            $table->decimal('chick_max_weight_g', 14, 1);
            $table->decimal('adult_min_weight_g', 14, 1);
            $table->decimal('adult_max_weight_g', 14, 1);
            $table->string('captured_unit', 2);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique('singleton_key');
        });
        DB::statement("ALTER TABLE weighing_reference_settings ADD CONSTRAINT weighing_reference_settings_values_check CHECK (singleton_key = true AND adult_from_week > 0 AND chick_min_weight_g > 0 AND chick_min_weight_g < chick_max_weight_g AND adult_min_weight_g > 0 AND adult_min_weight_g < adult_max_weight_g AND captured_unit IN ('g', 'kg') AND version > 0)");

        Schema::create('weighings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->foreignId('poultry_house_id')->constrained('poultry_houses')->restrictOnDelete();
            $table->foreignId('production_unit_id')->constrained('production_units')->restrictOnDelete();
            $table->string('mode', 12);
            $table->timestampTz('occurred_at');
            $table->string('captured_unit', 2);
            $table->text('notes')->nullable();
            $table->string('stage', 10)->nullable();
            $table->decimal('min_weight_g', 14, 1)->nullable();
            $table->decimal('max_weight_g', 14, 1)->nullable();
            $table->unsignedInteger('reference_adult_from_week')->nullable();
            $table->decimal('reference_chick_min_weight_g', 14, 1)->nullable();
            $table->decimal('reference_chick_max_weight_g', 14, 1)->nullable();
            $table->decimal('reference_adult_min_weight_g', 14, 1)->nullable();
            $table->decimal('reference_adult_max_weight_g', 14, 1)->nullable();
            $table->string('reference_unit', 2)->nullable();
            $table->unsignedInteger('reference_version')->nullable();
            $table->unsignedInteger('represented_bird_count');
            $table->decimal('total_weight_g', 18, 1);
            $table->decimal('average_weight_g', 18, 6);
            $table->boolean('outside_expected_range')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['flock_id', 'occurred_at', 'id']);
            $table->index(['mode', 'occurred_at', 'id']);
        });
        DB::statement("ALTER TABLE weighings ADD CONSTRAINT weighings_values_check CHECK (mode IN ('individual', 'group') AND captured_unit IN ('g', 'kg') AND (stage IS NULL OR stage IN ('chick', 'adult')) AND (reference_unit IS NULL OR reference_unit IN ('g', 'kg')) AND (reference_version IS NULL OR reference_version > 0) AND (reference_adult_from_week IS NULL OR reference_adult_from_week > 0) AND represented_bird_count > 0 AND total_weight_g > 0 AND average_weight_g > 0 AND version > 0 AND ((stage IS NULL AND min_weight_g IS NULL AND max_weight_g IS NULL) OR (stage IS NOT NULL AND min_weight_g > 0 AND max_weight_g > min_weight_g)))");

        Schema::create('weighing_measurements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weighing_id')->constrained('weighings')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->decimal('weight_g', 14, 1)->nullable();
            $table->decimal('total_weight_g', 14, 1)->nullable();
            $table->unsignedInteger('bird_count')->nullable();
            $table->decimal('average_weight_g', 18, 6)->nullable();
            $table->boolean('outside_expected_range')->default(false);
            $table->timestampsTz();
            $table->unique(['weighing_id', 'position']);
        });
        DB::statement('ALTER TABLE weighing_measurements ADD CONSTRAINT weighing_measurements_values_check CHECK (position > 0 AND ((weight_g IS NOT NULL AND weight_g > 0 AND total_weight_g IS NULL AND bird_count IS NULL AND average_weight_g IS NULL) OR (weight_g IS NULL AND total_weight_g IS NOT NULL AND total_weight_g > 0 AND bird_count IS NOT NULL AND bird_count > 0 AND average_weight_g IS NOT NULL AND average_weight_g > 0)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('weighing_measurements');
        Schema::dropIfExists('weighings');
        Schema::dropIfExists('weighing_reference_settings');
    }
};
