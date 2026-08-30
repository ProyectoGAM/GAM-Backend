<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Contracts;

use App\Modules\ReportingAndAnalytics\Application\Data\ReportQueryData;
use App\Modules\ReportingAndAnalytics\Application\Data\ReportResultData;
use App\Modules\ReportingAndAnalytics\Domain\Data\ReportSourceDefinition;
use Illuminate\Support\LazyCollection;

interface ReportSource
{
    public function definition(): ReportSourceDefinition;

    public function preview(ReportQueryData $query): ReportResultData;

    /** @return LazyCollection<int, array<string, mixed>> */
    public function rows(ReportQueryData $query): LazyCollection;
}
