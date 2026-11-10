<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['plan_template_activities', 'flock_plan_activities'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('practice_type', 40)->nullable();
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_practice_type_check CHECK (practice_type IS NULL OR (type = 'manual_practice' AND practice_type IN ('beak_trimming', 'nest_placement', 'other')))");
        }

        Schema::table('manual_practices', function (Blueprint $table): void {
            $table->text('notes')->nullable()->change();
        });
        DB::statement("ALTER TABLE manual_practices ADD CONSTRAINT manual_practices_optional_notes_check CHECK (notes IS NOT NULL OR practice_type = 'beak_trimming')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE manual_practices DROP CONSTRAINT manual_practices_optional_notes_check');
        DB::table('manual_practices')->whereNull('notes')->update(['notes' => '']);
        Schema::table('manual_practices', function (Blueprint $table): void {
            $table->text('notes')->nullable(false)->change();
        });

        foreach (['plan_template_activities', 'flock_plan_activities'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_practice_type_check");
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('practice_type');
            });
        }
    }
};
