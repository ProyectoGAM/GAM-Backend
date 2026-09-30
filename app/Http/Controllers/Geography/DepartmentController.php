<?php

namespace App\Http\Controllers\Geography;

use App\Actions\Geography\CreateDepartmentAction;
use App\Actions\Geography\UpdateDepartmentAction;
use App\Http\Requests\Geography\ListDepartmentsRequest;
use App\Http\Requests\Geography\StoreDepartmentRequest;
use App\Http\Requests\Geography\UpdateDepartmentRequest;
use App\Http\Resources\Geography\DepartmentResource;
use App\Models\Geography\Department;
use App\Models\User;
use App\Queries\Geography\ListDepartmentsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class DepartmentController
{
    public function index(
        ListDepartmentsRequest $request,
        ListDepartmentsQuery $query,
    ): AnonymousResourceCollection {
        return DepartmentResource::collection($query->execute($request->validated()));
    }

    public function store(
        StoreDepartmentRequest $request,
        CreateDepartmentAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $department = $action->execute($request->safe()->only(['name']), $actor);

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateDepartmentRequest $request,
        Department $department,
        UpdateDepartmentAction $action,
    ): DepartmentResource {
        /** @var User $actor */
        $actor = $request->user();

        return new DepartmentResource($action->execute(
            $department,
            $request->safe()->only(['name']),
            $actor,
        ));
    }
}
