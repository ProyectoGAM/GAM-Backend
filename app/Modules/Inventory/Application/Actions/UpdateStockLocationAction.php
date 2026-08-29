<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use Illuminate\Support\Facades\DB;

final readonly class UpdateStockLocationAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{production_unit_id?: int|null, name?: string} $attributes */
    public function execute(StockLocation $location, array $attributes, User $actor): StockLocation
    {
        return DB::transaction(function () use ($location, $attributes, $actor): StockLocation {
            $locked = StockLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            $locked->fill($attributes)->save();
            $after = $this->snapshot($locked);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'inventory',
                event: 'stock_location_updated',
                description: 'Ubicación de stock actualizada',
                properties: ['subject_snapshot' => $after],
                attributeChanges: ['old' => $before, 'new' => $after],
                upId: $locked->production_unit_id,
                source: 'api',
            ));

            return $locked->load('productionUnit');
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(StockLocation $location): array
    {
        return [
            'production_unit_id' => $location->production_unit_id,
            'name' => $location->name,
            'status' => $location->status->value,
        ];
    }
}
