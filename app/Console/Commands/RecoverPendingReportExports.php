<?php

namespace App\Console\Commands;

use App\Jobs\ReportingAndAnalytics\GenerateReportExport;
use App\Models\ReportingAndAnalytics\ReportExport;
use Illuminate\Console\Command;

final class RecoverPendingReportExports extends Command
{
    protected $signature = 'app:recover-pending-report-exports';

    protected $description = 'Reencola solicitudes pendientes cuando el despacho inicial no se completó';

    public function handle(): int
    {
        // La unicidad distribuida conserva un único job por exportación pendiente.
        ReportExport::query()->where('status', 'pending')->where('created_at', '<', now()->subMinute())
            ->where('expires_at', '>', now())->lazyById(100)->each(function (ReportExport $export): void {
                GenerateReportExport::dispatch($export->id)->onConnection('redis')->onQueue('reporting')->afterCommit();
            });

        return self::SUCCESS;
    }
}
