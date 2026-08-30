<?php

namespace App\Modules\ReportingAndAnalytics\Domain\Enums;

enum ReportExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Expired], true);
    }
}
