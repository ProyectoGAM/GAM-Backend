<?php

namespace App\Modules\Geography\Application\Actions;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final readonly class CreateLocalityAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{name: string} $attributes */
    public function execute(Department $department, array $attributes, User $actor): Locality
    {
        return DB::transaction(function () use ($department, $attributes, $actor): Locality {
            $locality = $department->localities()->create($attributes);
            $snapshot = [
                'department_id' => $locality->department_id,
                'name' => $locality->name,
            ];

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locality,
                actor: $actor,
                logName: 'geography',
                event: 'locality_created',
                description: 'Localidad creada',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                source: 'api',
            ));

            return $locality->load('department');
        });
    }
}
