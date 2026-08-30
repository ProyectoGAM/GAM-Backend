<?php

namespace App\Models\ReportingAndAnalytics;

use App\Models\User;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportFormat;
use App\Modules\ReportingAndAnalytics\Domain\Enums\ReportExportStatus;
use Database\Factories\ReportingAndAnalytics\ReportExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'operation_id',
    'idempotency_key_hash',
    'payload_hash',
    'source_key',
    'definition_version',
    'query',
    'format',
    'status',
    'disk',
    'path',
    'file_name',
    'mime_type',
    'file_size',
    'expires_at',
    'completed_at',
    'failed_at',
    'failure_code',
    'failure_message',
])]
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'query' => 'array',
            'format' => ReportExportFormat::class,
            'status' => ReportExportStatus::class,
            'file_size' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
