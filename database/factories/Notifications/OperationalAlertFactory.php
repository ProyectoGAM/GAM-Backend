<?php

namespace Database\Factories\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Notifications\OperationalAlert;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OperationalAlert> */
final class OperationalAlertFactory extends Factory
{
    protected $model = OperationalAlert::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'type' => OperationalAlertType::VehicleDocumentExpiring,
            'source_key' => (string) Str::uuid(),
            'view_permission' => 'can_view_vehicle_documents',
            'entity_type' => 'vehicle_document',
            'entity_id' => (string) Str::ulid(),
            'title' => 'Vencimiento de documento de vehículo',
            'urgency' => NotificationUrgency::Medium,
            'occurred_at' => now(),
        ];
    }
}
