<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private array $columns = [
        'vehicle_usages' => ['start_engine_hours', 'end_engine_hours'],
        'vehicle_maintenance_plans' => ['interval_hours', 'last_engine_hours', 'next_due_engine_hours'],
        'vehicle_maintenances' => ['engine_hours'],
    ];

    public function up(): void
    {
        if (Schema::hasTable('vehicle_meter_readings')) {
            $hourReadingIds = DB::table('vehicle_meter_readings')->where('meter_type', 'engine_hours')->pluck('id');
            if ($hourReadingIds->isNotEmpty()) {
                DB::table('vehicle_meter_readings')->whereIn('corrects_reading_id', $hourReadingIds)->delete();
                DB::table('vehicle_meter_readings')->whereIn('id', $hourReadingIds)->delete();
            }
        }

        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $columnName) {
                if (Schema::hasColumn($tableName, $columnName)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn($columnName));
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $columnName) {
                if (! Schema::hasColumn($tableName, $columnName)) {
                    Schema::table($tableName, fn (Blueprint $table) => $table->decimal($columnName, 12, 1)->nullable());
                }
            }
        }
    }
};
