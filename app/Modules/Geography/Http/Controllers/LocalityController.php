<?php

namespace App\Modules\Geography\Http\Controllers;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\User;
use App\Modules\Geography\Application\Actions\CreateLocalityAction;
use App\Modules\Geography\Application\Actions\UpdateLocalityAction;
use App\Modules\Geography\Application\Queries\ListLocalitiesQuery;
use App\Modules\Geography\Http\Requests\ListLocalitiesRequest;
use App\Modules\Geography\Http\Requests\StoreLocalityRequest;
use App\Modules\Geography\Http\Requests\UpdateLocalityRequest;
use App\Modules\Geography\Http\Resources\LocalityResource;
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
