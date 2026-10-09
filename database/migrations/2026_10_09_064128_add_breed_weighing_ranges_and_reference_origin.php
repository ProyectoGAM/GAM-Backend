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
        Schema::table('breeds', function (Blueprint $table): void {
            $table->decimal('chick_min_weight_g', 14, 1)->nullable();
            $table->decimal('chick_max_weight_g', 14, 1)->nullable();
            $table->decimal('adult_min_weight_g', 14, 1)->nullable();
            $table->decimal('adult_max_weight_g', 14, 1)->nullable();
        });
        DB::statement('ALTER TABLE breeds ADD CONSTRAINT breeds_weighing_ranges_check CHECK (((chick_min_weight_g IS NULL AND chick_max_weight_g IS NULL) OR (chick_min_weight_g > 0 AND chick_max_weight_g > chick_min_weight_g)) AND ((adult_min_weight_g IS NULL AND adult_max_weight_g IS NULL) OR (adult_min_weight_g > 0 AND adult_max_weight_g > adult_min_weight_g)))');

        Schema::table('weighings', function (Blueprint $table): void {
            $table->string('reference_chick_source', 10)->nullable();
            $table->string('reference_adult_source', 10)->nullable();
            $table->foreignId('reference_breed_id')->nullable()->constrained('breeds')->restrictOnDelete();
            $table->unsignedInteger('reference_breed_version')->nullable();
        });
        DB::statement("ALTER TABLE weighings ADD CONSTRAINT weighings_reference_sources_check CHECK ((reference_chick_source IS NULL OR reference_chick_source IN ('global', 'breed')) AND (reference_adult_source IS NULL OR reference_adult_source IN ('global', 'breed')))");

        Schema::table('daily_weighings', function (Blueprint $table): void {
            $table->string('reference_source', 10)->nullable();
            $table->foreignId('reference_breed_id')->nullable()->constrained('breeds')->restrictOnDelete();
            $table->unsignedInteger('reference_breed_version')->nullable();
        });
        DB::statement("ALTER TABLE daily_weighings ADD CONSTRAINT daily_weighings_reference_source_check CHECK (reference_source IS NULL OR reference_source IN ('global', 'breed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE daily_weighings DROP CONSTRAINT daily_weighings_reference_source_check');
        Schema::table('daily_weighings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reference_breed_id');
            $table->dropColumn(['reference_source', 'reference_breed_version']);
        });
        DB::statement('ALTER TABLE weighings DROP CONSTRAINT weighings_reference_sources_check');
        Schema::table('weighings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reference_breed_id');
            $table->dropColumn(['reference_chick_source', 'reference_adult_source', 'reference_breed_version']);
        });
        DB::statement('ALTER TABLE breeds DROP CONSTRAINT breeds_weighing_ranges_check');
        Schema::table('breeds', function (Blueprint $table): void {
            $table->dropColumn(['chick_min_weight_g', 'chick_max_weight_g', 'adult_min_weight_g', 'adult_max_weight_g']);
        });
    }
};
