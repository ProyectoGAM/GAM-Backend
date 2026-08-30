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
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final readonly class DepartmentController
{
    public function index(
        ListDepartmentsRequest $request,
        ListDepartmentsQuery $query,
    ): AnonymousResourceCollection {
        return DepartmentResource::collection($query->execute(PublicInputMapper::toInternal($request->validated())));
    }

    public function store(
        StoreDepartmentRequest $request,
        CreateDepartmentAction $action,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $departamento = $action->execute(PublicInputMapper::toInternal($request->safe()->only(['nombre'])), $actor);

        return (new DepartmentResource($departamento))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateDepartmentRequest $request,
        Department $departamento,
        UpdateDepartmentAction $action,
    ): DepartmentResource {
        /** @var User $actor */
        $actor = $request->user();

        return new DepartmentResource($action->execute(
            $departamento,
            PublicInputMapper::toInternal($request->safe()->only(['nombre'])),
            $actor,
        ));
    }
}
