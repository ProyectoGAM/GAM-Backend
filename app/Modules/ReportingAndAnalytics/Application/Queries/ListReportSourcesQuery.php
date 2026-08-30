<?php

namespace App\Modules\ReportingAndAnalytics\Application\Queries;

use App\Models\User;
use App\Modules\ReportingAndAnalytics\Application\Services\ReportSourceRegistry;
use App\Modules\ReportingAndAnalytics\Domain\Contracts\ReportSource;

final readonly class ListReportSourcesQuery
{
    public function __construct(private ReportSourceRegistry $registry) {}

    /** @return list<array<string, mixed>> */
    public function execute(User $actor): array
    {
        return array_map(
            fn (ReportSource $source): array => $source->definition()->toArray(),
            $this->registry->authorizedFor($actor),
        );
    }
}
