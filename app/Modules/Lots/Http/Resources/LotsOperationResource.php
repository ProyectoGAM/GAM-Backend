<?php

namespace App\Modules\Lots\Http\Resources;

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
        $result = ['id_operacion' => $operation->operation_id];
        $resources = [
            'flock' => ['lote', FlockResource::class],
            'destination' => ['lote_destino', FlockResource::class],
            'movement' => ['movimiento', FlockMovementResource::class],
            'mortality' => ['mortalidad', MortalityResource::class],
            'collection' => ['recoleccion', EggCollectionResource::class],
            'catalog' => ['catalogo', LotsCatalogResource::class],
        ];
        foreach ($resources as $key => [$public, $resource]) {
            if (isset($operation->result[$key])) {
                $result[$public] = (new $resource($operation->result[$key]))->resolve($request);
            }
        }

        return $result;
    }
}
