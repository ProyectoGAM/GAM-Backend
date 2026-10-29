<?php

namespace App\Queries\ReportingAndAnalytics;

use App\Interfaces\ReportingAndAnalytics\ReportSource;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;

final readonly class ListReportSourcesQuery
{
    public function __construct(private ReportSourceRegistry $registry) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $actor): array
    {
        return array_map(
            fn (ReportSource $source): array => [...$source->definition()->toArray(), 'capabilities' => [
                'export' => $this->registry->canExport($actor, $source),
                'share' => $this->registry->canShare($actor, $source),
                'presets' => $actor->hasRole('admin') || $actor->checkPermissionTo('reports.presets.manage'),
            ]],
            $this->registry->authorizedFor($actor),
        );
    }
}
