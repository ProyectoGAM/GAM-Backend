<?php

namespace App\Actions\Geography;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Geography\Department;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateDepartmentAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{name: string} $attributes */
    public function execute(Department $department, array $attributes, User $actor): Department
    {
        return DB::transaction(function () use ($department, $attributes, $actor): Department {
            $query = Department::query()->whereKey($department->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedDepartment = $query->firstOrFail();
            $before = ['name' => $lockedDepartment->name];

            $lockedDepartment->fill($attributes)->save();
            $after = ['name' => $lockedDepartment->name];

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedDepartment,
                actor: $actor,
                logName: 'geography',
                event: 'department_updated',
                description: 'Departamento actualizado',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                source: 'api',
            ));

            return $lockedDepartment;
        });
    }
}
