<?php

namespace App\Providers;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Policies\ReportingAndAnalytics\ReportExportPolicy;
use App\Policies\ReportingAndAnalytics\ReportPresetPolicy;
use App\Services\Inventory\InventoryMovementsReportSource;
use App\Services\Inventory\InventoryStockBalancesReportSource;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ReportingAndAnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportSourceRegistry::class, function (): ReportSourceRegistry {
            return new ReportSourceRegistry([
                new InventoryStockBalancesReportSource,
                new InventoryMovementsReportSource,
            ]);
        });
    }

    public function boot(): void
    {
        Gate::policy(ReportPreset::class, ReportPresetPolicy::class);
        Gate::policy(ReportExport::class, ReportExportPolicy::class);
    }
}
