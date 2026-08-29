<?php

namespace App\Modules\AuditAndTraceability\Application\Contracts;

use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;

interface AuditRecorder
{
    public function record(AuditEntryData $entry): void;
}
