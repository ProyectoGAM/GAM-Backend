<?php

namespace App\Modules\Geography\Application\Actions;

use App\Models\Geography\Locality;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final readonly class UpdateLocalityAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{department_id?: int, name?: string} $attributes */
    public function execute(Locality $locality, array $attributes, User $actor): Locality
    {
        return DB::transaction(function () use ($locality, $attributes, $actor): Locality {
            $query = Locality::query()->whereKey($locality->getKey());
            $query->getQuery()->lockForUpdate();
            $lockedLocality = $query->firstOrFail();
            $before = $this->snapshot($lockedLocality);

            $lockedLocality->fill($attributes)->save();
            $after = $this->snapshot($lockedLocality);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $lockedLocality,
                actor: $actor,
                logName: 'geography',
                event: 'locality_updated',
                description: 'Localidad actualizada',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                source: 'api',
            ));

            return $lockedLocality->load('department');
        });
    }

    /** @return array{department_id: int, name: string} */
    private function snapshot(Locality $locality): array
    {
        return [
            'department_id' => $locality->department_id,
            'name' => $locality->name,
        ];
    }
}
