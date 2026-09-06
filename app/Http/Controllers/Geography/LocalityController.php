<?php

namespace App\Http\Controllers\Geography;

use App\Actions\Geography\CreateLocalityAction;
use App\Actions\Geography\UpdateLocalityAction;
use App\Http\Requests\Geography\ListLocalitiesRequest;
use App\Http\Requests\Geography\StoreLocalityRequest;
use App\Http\Requests\Geography\UpdateLocalityRequest;
use App\Http\Resources\Geography\LocalityResource;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\User;
use App\Queries\Geography\ListLocalitiesQuery;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class LocalityController
{
    public function index(
        ListLocalitiesRequest $request,
        Department $departamento,
        ListLocalitiesQuery $query,
    ): AnonymousResourceCollection {
        return LocalityResource::collection($query->execute($departamento, PublicInputMapper::toInternal($request->validated())));
    }

    public function store(
        StoreLocalityRequest $request,
        Department $departamento,
        CreateLocalityAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $localidad = $action->execute($departamento, PublicInputMapper::toInternal($request->safe()->only(['nombre'])), $actor);

        return (new LocalityResource($localidad))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateLocalityRequest $request,
        Locality $localidad,
        UpdateLocalityAction $action,
    ): LocalityResource {
        /** @var User $actor */
        $actor = $request->user();

        return new LocalityResource($action->execute(
            $localidad,
            PublicInputMapper::toInternal($request->safe()->only(['departamento_id', 'nombre'])),
            $actor,
        ));
    }
}
