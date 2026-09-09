<?php

namespace App\Http\Resources\Lots;

use App\Models\Lots\FlockOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LotsOperationResource extends JsonResource
{
    /** La creación del registro idempotente no determina el estado HTTP del comando. */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FlockOperation $operation */
        $operation = $this->resource;
        $result = ['operation_id' => $operation->operation_id];
        $resources = [
            'flock' => ['flock', FlockResource::class],
            'destination' => ['destination_flock', FlockResource::class],
            'movement' => ['movement', FlockMovementResource::class],
            'mortality' => ['mortality', MortalityResource::class],
            'collection' => ['collection', EggCollectionResource::class],
            'catalog' => ['catalog', LotsCatalogResource::class],
            'weighing' => ['weighing', WeighingResource::class],
            'settings' => ['settings', WeighingSettingsResource::class],
        ];
        foreach ($resources as $key => [$public, $resource]) {
            if (isset($operation->result[$key])) {
                $result[$public] = (new $resource($operation->result[$key]))->resolve($request);
            }
        }
        if (array_key_exists('warnings', $operation->result)) {
            $result['warnings'] = $operation->result['warnings'];
        }

        return $result;
    }
}
