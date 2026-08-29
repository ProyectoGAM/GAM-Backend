<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\Inventory\Domain\Enums\StockLocationStatus;
use App\Modules\Inventory\Domain\Exceptions\InventoryConflict;
use Illuminate\Support\Facades\DB;

final readonly class ChangeStockLocationStatusAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(StockLocation $location, StockLocationStatus $status, User $actor): StockLocation
    {
        return DB::transaction(function () use ($location, $status, $actor): StockLocation {
            $locked = StockLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();

            if ($status === StockLocationStatus::Inactive && $locked->stockBalances()->where(function ($query): void {
                $query->where('on_hand_quantity', '>', 0)->orWhere('reserved_quantity', '>', 0);
            })->exists()) {
                throw new InventoryConflict('Una ubicación con stock físico o reservado no puede desactivarse.');
            }

            $before = $locked->status->value;
            $locked->forceFill(['status' => $status])->save();
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $locked,
                actor: $actor,
                logName: 'inventory',
                event: 'stock_location_status_changed',
                description: 'Estado de ubicación de stock actualizado',
                properties: ['subject_snapshot' => $this->snapshot($locked)],
                attributeChanges: ['old' => ['status' => $before], 'new' => ['status' => $status->value]],
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
            'id' => (int) $location->getKey(),
            'name' => $location->name,
            'status' => $location->status->value,
        ];
    }
}
