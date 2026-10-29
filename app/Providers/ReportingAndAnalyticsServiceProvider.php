<?php

namespace App\Providers;

use App\Models\ReportingAndAnalytics\ReportExport;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Policies\ReportingAndAnalytics\ReportExportPolicy;
use App\Policies\ReportingAndAnalytics\ReportPresetPolicy;
use App\Services\Deliveries\DeliveriesReportSource;
use App\Services\Inventory\EggOutflowReportSource;
use App\Services\Inventory\InventoryMovementsReportSource;
use App\Services\Inventory\InventoryStockBalancesReportSource;
use App\Services\Lots\EggProductionReportSource;
use App\Services\Lots\FlockAdmissionsReportSource;
use App\Services\Lots\WeighingMeasurementsReportSource;
use App\Services\ManagementPlans\FeedSupplyReportSource;
use App\Services\ManagementPlans\ManagementExecutionsReportSource;
use App\Services\ManagementPlans\VaccinationReportSource;
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
                $this->app->make(EggProductionReportSource::class),
                $this->app->make(FlockAdmissionsReportSource::class),
                $this->app->make(WeighingMeasurementsReportSource::class),
                $this->app->make(VaccinationReportSource::class),
                $this->app->make(ManagementExecutionsReportSource::class),
                $this->app->make(FeedSupplyReportSource::class),
                $this->app->make(DeliveriesReportSource::class),
                $this->app->make(EggOutflowReportSource::class),
            ]);
        });
    }

    public function boot(): void
    {
        Gate::policy(ReportPreset::class, ReportPresetPolicy::class);
        Gate::policy(ReportExport::class, ReportExportPolicy::class);
    }
}
