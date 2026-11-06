<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->boolean('is_alert')->default(false);
            $table->timestampTz('resolved_at')->nullable();
        });

        DB::statement('ALTER TABLE app_notifications ADD CONSTRAINT app_notifications_alert_resolution_check CHECK (is_alert OR resolved_at IS NULL)');
    }

    public function down(): void
    {
        Schema::table('app_notifications', function (Blueprint $table): void {
            $table->dropColumn(['is_alert', 'resolved_at']);
        });
    }
};
