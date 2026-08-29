<?php

namespace App\Modules\Geography\Http\Controllers;

use App\Models\Geography\Department;
use App\Models\User;
use App\Modules\Geography\Application\Actions\CreateDepartmentAction;
use App\Modules\Geography\Application\Actions\UpdateDepartmentAction;
use App\Modules\Geography\Application\Queries\ListDepartmentsQuery;
use App\Modules\Geography\Http\Requests\ListDepartmentsRequest;
use App\Modules\Geography\Http\Requests\StoreDepartmentRequest;
use App\Modules\Geography\Http\Requests\UpdateDepartmentRequest;
use App\Modules\Geography\Http\Resources\DepartmentResource;
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
