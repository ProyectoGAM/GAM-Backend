<?php

namespace App\Providers;

use App\Infrastructure\AuditAndTraceability\SpatieAuditRecorder;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use Illuminate\Support\ServiceProvider;

final class AuditAndTraceabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuditRecorder::class, SpatieAuditRecorder::class);
    }
}
