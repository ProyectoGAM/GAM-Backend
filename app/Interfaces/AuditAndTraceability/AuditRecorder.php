<?php

namespace App\Interfaces\AuditAndTraceability;

use App\DTO\AuditAndTraceability\AuditEntryData;

interface AuditRecorder
{
    public function record(AuditEntryData $entry): void;
}
