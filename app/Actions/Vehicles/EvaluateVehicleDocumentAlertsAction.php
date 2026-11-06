<?php

namespace App\Actions\Vehicles;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\Notifications\NotificationUrgency;
use App\Interfaces\Clock;
use App\Models\Vehicles\VehicleDocument;
use App\Models\Vehicles\VehicleMaintenance;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateVehicleDocumentAlertsAction
{
    public function __construct(private SynchronizeOperationalAlertsAction $synchronize, private Clock $clock) {}

    public function execute(): void
    {
        DB::transaction(function (): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['vehicle-document-alerts']);
            $evaluatedAt = $this->clock->now()->setTimezone(VehicleMaintenance::TIMEZONE);
            $today = $evaluatedAt->toDateString();
            $documents = VehicleDocument::query()->currentExpiring()
                ->where('expires_at', '<=', $evaluatedAt->addDays(VehicleDocument::EXPIRY_NOTICE_DAYS)->toDateString())
                ->whereHas('vehicle', fn ($vehicle) => $vehicle->where('status', '!=', 'retired'))
                ->with('vehicle:id,public_id,plate');
            $pending = [];

            foreach ($documents->lazyById(100) as $document) {
                $vehicle = $document->vehicle;
                $expired = $document->expires_at->toDateString() < $today;
                $date = $document->expires_at->format('d/m/Y');
                $state = $expired ? 'venció el' : 'vence el';
                $pending['vehicle-document:'.$document->public_id] = [
                    'entity_id' => $document->public_id,
                    'target_path' => '/administracion/vehiculos/'.$vehicle->public_id,
                    'urgency' => $expired ? NotificationUrgency::High : NotificationUrgency::Low,
                    'title' => $expired ? 'Documento de vehículo vencido' : 'Documento de vehículo próximo a vencer',
                    'message' => "El documento «{$document->title}» del vehículo {$vehicle->plate} {$state} {$date}.",
                ];
            }

            $this->synchronize->execute(null, 'vehicles', 'vehicle_document_expiring', 'can_view_vehicle_documents', 'vehicle_document', $evaluatedAt, $pending);
        }, 3);
    }
}
