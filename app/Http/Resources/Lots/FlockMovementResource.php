<?php

namespace App\Http\Resources\Lots;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return [
            'id' => $data['public_id'] ?? null,
            'operation_id' => $data['operation_id'] ?? null,
            'type' => $data['type'] ?? null,
            'quantity' => $data['quantity'] ?? null,
            'source_flock_id' => $data['source_flock_id'] ?? null,
            'destination_flock_id' => $data['destination_flock_id'] ?? null,
            'reversed_movement_id' => $data['reverses_movement_id'] ?? null,
            'source_poultry_house_id' => $data['source_poultry_house_id'] ?? null,
            'destination_poultry_house_id' => $data['destination_poultry_house_id'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
            'created_at' => $data['created_at'] ?? null,
            'reason' => $data['reason'] ?? null,
            'created_by' => $data['created_by'] ?? null,
            'before' => (object) array_map(fn (array $flock): array => (new FlockResource($flock))->resolve($request), $data['before']),
            'after' => (object) array_map(fn (array $flock): array => (new FlockResource($flock))->resolve($request), $data['after']),
        ];
    }
}
