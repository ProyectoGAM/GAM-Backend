<?php

namespace App\Providers;

use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Infrastructure\Activitylog\SpatieAuditRecorder;
use Illuminate\Support\ServiceProvider;

final class AuditAndTraceabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, SpatieAuditRecorder::class);
    }
}
