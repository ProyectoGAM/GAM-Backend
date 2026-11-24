<?php

namespace Database\Seeders;

use Database\Seeders\ReportingAndAnalytics\DefaultReportPresetsSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([IdentityPermissionSeeder::class, AdminUserSeeder::class, GeographySeeder::class, DefaultReportPresetsSeeder::class]);

        if (app()->environment('local') || (app()->environment('production') && config('app.demo_db_reset_enabled'))) {
            $this->call(LocalDemoDataSeeder::class);
        }
    }
}
