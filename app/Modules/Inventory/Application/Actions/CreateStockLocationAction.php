<?php

namespace App\Modules\Inventory\Application\Actions;

use App\Models\Inventory\StockLocation;
use App\Models\User;
use App\Modules\AuditAndTraceability\Application\Contracts\AuditRecorder;
use App\Modules\AuditAndTraceability\Application\Data\AuditEntryData;
use App\Modules\Inventory\Domain\Enums\StockLocationStatus;
use Illuminate\Support\Facades\DB;

final readonly class CreateStockLocationAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{production_unit_id?: int|null, name: string} $attributes */
    public function execute(array $attributes, User $actor): StockLocation
    {
        return DB::transaction(function () use ($attributes, $actor): StockLocation {
            $location = StockLocation::query()->create([
                ...$attributes,
                'status' => StockLocationStatus::Active,
            ]);
            $snapshot = $this->snapshot($location);

            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $location,
                actor: $actor,
                logName: 'inventory',
                event: 'stock_location_created',
                description: 'Ubicación de stock creada',
                properties: ['subject_snapshot' => $snapshot],
                attributeChanges: ['old' => [], 'new' => $snapshot],
                upId: $location->production_unit_id,
                source: 'api',
            ));

            return $location->load('productionUnit');
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
