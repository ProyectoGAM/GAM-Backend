<?php

namespace App\Modules\Geography\Application\Actions;

use App\Models\Geography\Department;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final readonly class CreateDepartmentAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{name: string} $attributes */
    public function execute(array $attributes, User $actor): Department
    {
        return DB::transaction(function () use ($attributes, $actor): Department {
            $department = Department::query()->create($attributes);
            $snapshot = ['name' => $department->name];

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $department,
                actor: $actor,
                logName: 'geography',
                event: 'department_created',
                description: 'Departamento creado',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                source: 'api',
            ));

            return $department;
        });
    }
}
