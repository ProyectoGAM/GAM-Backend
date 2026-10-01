<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flocks', function (Blueprint $table): void {
            $table->boolean('is_grouped')->default(false);
        });

        DB::table('flocks')
            ->whereIn('id', DB::table('flock_movements')
                ->select('destination_flock_id')
                ->whereIn('type', ['partial_existing', 'total_existing']))
            ->update(['is_grouped' => true]);
    }

    public function down(): void
    {
        Schema::table('flocks', function (Blueprint $table): void {
            $table->dropColumn('is_grouped');
        });
    }
};
