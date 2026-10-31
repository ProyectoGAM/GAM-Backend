<?php

namespace App\Http\Resources\Vehicles;

use App\Models\Vehicles\Vehicle;
use App\Services\Vehicles\VehicleAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Vehicle */
final class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $availability = $this->resource->getAttribute('vehicle_availability') ?? app(VehicleAvailability::class)->describe($this->resource);

        return [
            'id' => $this->public_id, 'plate' => $this->plate, 'make' => $this->make, 'model' => $this->model,
            'year' => $this->year, 'admitted_at' => $this->admitted_at?->toDateString(), 'status' => $this->status,
            'status_reason' => $this->status_reason, 'capacity_kg' => $this->capacity_kg,
            'habitual_driver' => $this->whenLoaded('habitualDriver', fn () => $this->habitualDriver === null ? null : ['id' => $this->habitualDriver->id, 'name' => $this->habitualDriver->name]),
            'notes' => $this->notes, 'version' => $this->version,
            'availability' => $availability['availability'], 'availability_reason' => $availability['reason'], 'maintenance_warnings' => $availability['warnings'],
            'latest_odometer' => $this->when(isset($this->latest_odometer), $this->latest_odometer),
            'latest_odometer_at' => $this->when(isset($this->latest_odometer_at), $this->latest_odometer_at),
            'created_at' => $this->created_at, 'updated_at' => $this->updated_at,
        ];
    }
}
