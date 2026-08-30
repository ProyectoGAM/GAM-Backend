<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Enums;

enum ReportExportFormat: string
{
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';
}
