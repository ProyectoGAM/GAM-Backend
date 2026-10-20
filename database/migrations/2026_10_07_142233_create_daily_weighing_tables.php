<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_weighings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->date('local_date');
            $table->string('stage', 10)->nullable();
            $table->decimal('min_weight_g', 14, 1)->nullable();
            $table->decimal('max_weight_g', 14, 1)->nullable();
            $table->unsignedInteger('reference_version')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('represented_bird_count')->default(0);
            $table->decimal('total_weight_g', 18, 1)->default(0);
            $table->decimal('average_weight_g', 18, 6)->nullable();
            $table->unsignedInteger('anomalous_entry_count')->default(0);
            $table->timestampsTz();
            $table->unique(['flock_id', 'local_date']);
            $table->index(['local_date', 'id']);
        });

        Schema::create('daily_weighing_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('daily_weighing_id')->constrained('daily_weighings')->restrictOnDelete();
            $table->string('mode', 12);
            $table->decimal('weight_g', 14, 1)->nullable();
            $table->unsignedInteger('bird_count');
            $table->decimal('total_weight_g', 14, 1);
            $table->decimal('average_weight_g', 18, 6);
            $table->boolean('outside_expected_range')->default(false);
            $table->timestampTz('occurred_at');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('deletion_reason')->nullable();
            $table->timestampsTz();
            $table->index(['daily_weighing_id', 'deleted_at', 'occurred_at', 'id'], 'daily_weighing_entries_order_index');
        });
        DB::statement("ALTER TABLE daily_weighings ADD CONSTRAINT daily_weighings_values_check CHECK (version > 0 AND ((stage IS NULL AND min_weight_g IS NULL AND max_weight_g IS NULL AND reference_version IS NULL) OR (stage IN ('chick', 'adult') AND min_weight_g > 0 AND max_weight_g > min_weight_g AND reference_version > 0)) AND represented_bird_count >= 0 AND total_weight_g >= 0 AND anomalous_entry_count >= 0)");
        DB::statement("ALTER TABLE daily_weighing_entries ADD CONSTRAINT daily_weighing_entries_values_check CHECK (mode IN ('individual', 'group') AND bird_count > 0 AND total_weight_g > 0 AND average_weight_g > 0 AND ((mode = 'individual' AND bird_count = 1 AND weight_g = total_weight_g) OR (mode = 'group' AND weight_g IS NULL)))");
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_weighing_entries');
        Schema::dropIfExists('daily_weighings');
    }
};
