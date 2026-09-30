<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('pin_hash')->nullable()->after('password');
            $table->boolean('pin_enabled')->default(false)->after('pin_hash');
            $table->timestamp('pin_changed_at')->nullable()->after('pin_enabled');
            $table->string('pin_pepper_version', 32)->nullable()->after('pin_changed_at');
            $table->unsignedSmallInteger('pin_failed_attempts')->default(0)->after('pin_pepper_version');
            $table->timestamp('pin_failed_window_started_at')->nullable()->after('pin_failed_attempts');
            $table->timestamp('pin_locked_until')->nullable()->after('pin_failed_window_started_at');
            $table->unsignedSmallInteger('pin_daily_failed_attempts')->default(0)->after('pin_locked_until');
            $table->timestamp('pin_daily_window_started_at')->nullable()->after('pin_daily_failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'pin_hash',
                'pin_enabled',
                'pin_changed_at',
                'pin_pepper_version',
                'pin_failed_attempts',
                'pin_failed_window_started_at',
                'pin_locked_until',
                'pin_daily_failed_attempts',
                'pin_daily_window_started_at',
            ]);
        });
    }
};
