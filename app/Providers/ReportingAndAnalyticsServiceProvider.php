<?php

namespace App\Providers;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Modules\Inventory\Application\PublicApi\Reporting\InventoryMovementsReportSource;
use App\Modules\Inventory\Application\PublicApi\Reporting\InventoryStockBalancesReportSource;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Policies\ReportingAndAnalytics\ReportExportPolicy;
use App\Policies\ReportingAndAnalytics\ReportPresetPolicy;
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
