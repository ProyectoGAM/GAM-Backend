<?php

namespace App\Http\Resources\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Services\Deliveries\DeliveryPricing;
use App\Services\Deliveries\DeliveryUnitBalance;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeliveryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $deliveredQuantity = $this->relationLoaded('stops')
            ? (int) $this->stops->where('status', DeliveryStopStatus::Delivered)->sum('delivered_quantity')
            : null;

        return [
            'id' => $this->public_id,
            'status' => $this->status->value,
            'driver' => $this->when($this->relationLoaded('driver'), fn (): array => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
            ]),
            'production_unit' => $this->when($this->relationLoaded('productionUnit'), fn (): array => [
                'id' => $this->productionUnit->id,
                'name' => $this->productionUnit->name,
                'latitude' => $this->productionUnit->latitude,
                'longitude' => $this->productionUnit->longitude,
            ]),
            'vehicle_reference' => $this->vehicle_reference,
            'loaded_quantity' => (int) $this->loaded_quantity,
            'delivered_quantity' => $deliveredQuantity,
            'returned_quantity' => (int) $this->returned_quantity,
            'returned_items' => $this->returned_items,
            'return_production_unit' => $this->when($this->relationLoaded('returnProductionUnit'), fn () => $this->returnProductionUnit === null ? null : [
                'id' => $this->returnProductionUnit->id, 'name' => $this->returnProductionUnit->name,
            ]),
            'delivered_amount' => $this->relationLoaded('stops') ? app(DeliveryPricing::class)->total($this->stops)
                : (($this->unpriced_stops_count ?? 0) > 0 ? null : ($this->delivered_amount ?? '0.000')),
            'currency' => Money::CURRENCY,
            'remaining_quantity' => $deliveredQuantity === null
                ? null
                : (int) $this->loaded_quantity - $deliveredQuantity - (int) $this->returned_quantity,
            'unit_balances' => $this->when(
                $this->relationLoaded('loads') && $this->relationLoaded('stops'),
                fn (): array => app(DeliveryUnitBalance::class)->summarize($this->resource),
            ),
            'stops_summary' => [
                'total' => $this->stops_count ?? null,
                'pending' => $this->pending_stops_count ?? null,
                'delivered' => $this->delivered_stops_count ?? null,
                'not_delivered' => $this->not_delivered_stops_count ?? null,
            ],
            'started_at' => $this->started_at,
            'closed_at' => $this->closed_at,
            'close_notes' => $this->close_notes,
            'latest_location' => $this->when(
                $this->relationLoaded('latestLocation') && $this->latestLocation !== null,
                fn (): DeliveryLocationResource => new DeliveryLocationResource($this->latestLocation),
            ),
            'loads' => DeliveryLoadResource::collection($this->whenLoaded('loads')),
            'stops' => DeliveryStopResource::collection($this->whenLoaded('stops')),
            'locations' => DeliveryLocationResource::collection($this->whenLoaded('locations')),
        ];
    }
}
