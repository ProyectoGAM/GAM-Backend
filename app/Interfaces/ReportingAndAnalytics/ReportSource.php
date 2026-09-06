<?php

namespace App\Interfaces\ReportingAndAnalytics;

use App\DTO\ReportingAndAnalytics\ReportQueryData;
use App\DTO\ReportingAndAnalytics\ReportResultData;
use App\DTO\ReportingAndAnalytics\ReportSourceDefinition;
use Illuminate\Support\LazyCollection;

interface ReportSource
{
    public function definition(): ReportSourceDefinition;

    public function preview(ReportQueryData $query): ReportResultData;

    /** @return LazyCollection<int, array<string, mixed>> */
    public function rows(ReportQueryData $query): LazyCollection;
}
