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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class LocalityController
{
    public function index(
        ListLocalitiesRequest $request,
        Department $department,
        ListLocalitiesQuery $query,
    ): AnonymousResourceCollection {
        return LocalityResource::collection($query->execute($department, $request->validated()));
    }

    public function store(
        StoreLocalityRequest $request,
        Department $department,
        CreateLocalityAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $locality = $action->execute($department, $request->safe()->only(['name']), $actor);

        return (new LocalityResource($locality))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateLocalityRequest $request,
        Locality $locality,
        UpdateLocalityAction $action,
    ): LocalityResource {
        /** @var User $actor */
        $actor = $request->user();

        return new LocalityResource($action->execute(
            $locality,
            $request->safe()->only(['department_id', 'name']),
            $actor,
        ));
    }
}
