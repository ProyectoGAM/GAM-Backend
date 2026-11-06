<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['daily_weighing_entries', 'weighings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->timestampTz('anomaly_reviewed_at')->nullable();
                $table->foreignId('anomaly_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['daily_weighing_entries', 'weighings'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('anomaly_reviewed_by');
                $table->dropColumn('anomaly_reviewed_at');
            });
        }
    }
};
