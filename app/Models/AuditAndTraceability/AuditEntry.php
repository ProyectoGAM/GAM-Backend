<?php

namespace App\Models\AuditAndTraceability;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * @property string|null $operation_id
 * @property string|null $trace_id
 * @property string $source
 * @property int|null $up_id
 */
final class AuditEntry extends Activity
{
    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            if (! is_string($entry->operation_id) || $entry->operation_id === '') {
                $entry->operation_id = Str::uuid()->toString();
            }

            if (! is_string($entry->trace_id) || $entry->trace_id === '') {
                $traceId = Context::get('trace_id');
                $entry->trace_id = is_string($traceId) && $traceId !== ''
                    ? $traceId
                    : Str::uuid()->toString();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'operation_id' => 'string',
            'trace_id' => 'string',
            'up_id' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForOperation(Builder $query, string $operationId): Builder
    {
        return $query->where('operation_id', $operationId);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForUp(Builder $query, int $upId): Builder
    {
        return $query->where('up_id', $upId);
    }
}
