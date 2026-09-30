<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('poultry_houses', function (Blueprint $table) {
            $table->string('type', 20)->nullable();
            $table->index(['production_unit_id', 'type', 'status', 'name']);
        });

        DB::table('poultry_houses')
            ->whereNull('type')
            ->update(['type' => 'poultry']);

        DB::statement("ALTER TABLE poultry_houses ALTER COLUMN type SET DEFAULT 'poultry'");
        DB::statement('ALTER TABLE poultry_houses ALTER COLUMN type SET NOT NULL');
        DB::statement('ALTER TABLE poultry_houses ALTER COLUMN bird_capacity DROP NOT NULL');
        DB::statement('ALTER TABLE poultry_houses DROP CONSTRAINT poultry_houses_bird_capacity_check');
        DB::statement("ALTER TABLE poultry_houses ADD CONSTRAINT poultry_houses_type_check CHECK (type IN ('poultry', 'feed'))");
        DB::statement("ALTER TABLE poultry_houses ADD CONSTRAINT poultry_houses_type_capacity_check CHECK ((type = 'poultry' AND bird_capacity > 0) OR (type = 'feed' AND bird_capacity IS NULL))");
    }

    public function down(): void
    {
        if (DB::table('poultry_houses')->where('type', 'feed')->exists()) {
            throw new RuntimeException('No se puede revertir el tipo de galpón mientras existan plantas de ración.');
        }

        DB::statement('ALTER TABLE poultry_houses DROP CONSTRAINT poultry_houses_type_capacity_check');
        DB::statement('ALTER TABLE poultry_houses DROP CONSTRAINT poultry_houses_type_check');
        DB::statement('ALTER TABLE poultry_houses ALTER COLUMN bird_capacity SET NOT NULL');

        Schema::table('poultry_houses', function (Blueprint $table) {
            $table->dropIndex('poultry_houses_production_unit_id_type_status_name_index');
            $table->dropColumn('type');
        });

        DB::statement('ALTER TABLE poultry_houses ADD CONSTRAINT poultry_houses_bird_capacity_check CHECK (bird_capacity > 0)');
    }
};
