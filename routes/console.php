<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$cleanupSchedule = Schedule::command('app:cleanup-expired-report-exports');
match (config('reporting.cleanup_schedule', 'hourly')) {
    'daily' => $cleanupSchedule->daily(),
    'weekly' => $cleanupSchedule->weekly(),
    default => $cleanupSchedule->hourly(),
};
$cleanupSchedule->withoutOverlapping()->onOneServer();
