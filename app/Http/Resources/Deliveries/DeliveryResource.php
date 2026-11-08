<?php

namespace App\Http\Resources\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Http\Resources\CustomersAndAccounts\CustomerMovementResource;
use App\Services\Deliveries\DeliveryFinancialSummary;
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
            'production_unit' => $this->when($this->relationLoaded('productionUnit'), fn () => $this->productionUnit === null ? null : [
                'id' => $this->productionUnit->id,
                'name' => $this->productionUnit->name,
                'latitude' => $this->productionUnit->latitude,
                'longitude' => $this->productionUnit->longitude,
            ]),
            'started_without_load' => (bool) $this->started_without_load,
            'vehicle_reference' => $this->vehicle_reference,
            'vehicle' => $this->when($this->vehicle_snapshot !== null, fn (): array => $this->vehicle_snapshot),
            'vehicle_usage' => $this->when($this->relationLoaded('vehicleUsage') && $this->vehicleUsage !== null, fn (): array => [
                'id' => $this->vehicleUsage->public_id,
                'started_at' => $this->vehicleUsage->started_at,
                'ended_at' => $this->vehicleUsage->ended_at,
                'start_odometer_km' => $this->vehicleUsage->start_odometer_km,
                'end_odometer_km' => $this->vehicleUsage->end_odometer_km,
                'distance_km' => $this->vehicleUsage->start_odometer_km !== null && $this->vehicleUsage->end_odometer_km !== null && (float) $this->vehicleUsage->end_odometer_km >= (float) $this->vehicleUsage->start_odometer_km
                    ? number_format((float) $this->vehicleUsage->end_odometer_km - (float) $this->vehicleUsage->start_odometer_km, 1, '.', '') : null,
                'fuel_entries' => $this->vehicleUsage->relationLoaded('fuelEntries')
                    ? $this->vehicleUsage->fuelEntries->map(fn ($entry): array => [
                        'id' => $entry->public_id,
                        'occurred_at' => $entry->occurred_at,
                        'liters' => $entry->liters,
                        'fuel_type' => $entry->fuel_type,
                        'amount' => $entry->amount,
                        'currency' => $entry->currency,
                        'supplier' => $entry->supplier,
                        'odometer_km' => $entry->odometer_km,
                        'notes' => $entry->notes,
                    ])->values()
                    : [],
            ]),
            'loaded_quantity' => (int) $this->loaded_quantity,
            'delivered_quantity' => $deliveredQuantity,
            'returned_quantity' => (int) $this->returned_quantity,
            'returned_items' => $this->returned_items,
            'return_production_unit' => $this->when($this->relationLoaded('returnProductionUnit'), fn () => $this->returnProductionUnit === null ? null : [
                'id' => $this->returnProductionUnit->id, 'name' => $this->returnProductionUnit->name,
                'latitude' => $this->returnProductionUnit->latitude, 'longitude' => $this->returnProductionUnit->longitude,
            ]),
            'delivered_amount' => $this->relationLoaded('stops') ? app(DeliveryPricing::class)->total($this->stops)
                : (($this->unpriced_stops_count ?? 0) > 0 ? null : ($this->delivered_amount ?? '0.000')),
            'currency' => Money::CURRENCY,
            'financial_summary' => $this->when($this->relationLoaded('stops') && $this->relationLoaded('accountMovements'),
                fn () => $this->presentFinancialSummary(app(DeliveryFinancialSummary::class)->execute($this->resource), $request)),
            'closed_financial_summary' => $this->presentFinancialSummary($this->financial_snapshot, $request),
            'account_movements' => CustomerMovementResource::collection($this->whenLoaded('accountMovements')),
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
            'rectificaciones_entregas' => $this->whenLoaded('stopCorrections', fn () => $this->stopCorrections->pluck('result')->all()),
            'locations' => DeliveryLocationResource::collection($this->whenLoaded('locations')),
        ];
    }

    /** Los saldos empresariales no se exponen en el contexto de una sola UP. @param array<string, mixed>|null $summary @return array<string, mixed>|null */
    private function presentFinancialSummary(?array $summary, Request $request): ?array
    {
        if ($summary !== null && $request->user()?->usesDevicePin()) {
            $summary['customers'] = array_map(static function (array $customer): array {
                unset($customer['balance_before'], $customer['balance_after']);

                return $customer;
            }, $summary['customers'] ?? []);
        }

        return $summary;
    }
}
