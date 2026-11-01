<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->foreignId('vehicle_id')->nullable()->after('driver_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignId('vehicle_usage_id')->nullable()->after('vehicle_id')->constrained('vehicle_usages')->nullOnDelete();
            $table->json('vehicle_snapshot')->nullable()->after('vehicle_reference');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('vehicle_usage_id');
            $table->dropConstrainedForeignId('vehicle_id');
            $table->dropColumn('vehicle_snapshot');
        });
    }
};
