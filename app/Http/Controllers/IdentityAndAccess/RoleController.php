<?php

namespace App\Http\Controllers\IdentityAndAccess;

use App\Actions\IdentityAndAccess\CreateRoleAction;
use App\Actions\IdentityAndAccess\DeleteRoleAction;
use App\Actions\IdentityAndAccess\UpdateRoleAction;
use App\Actions\IdentityAndAccess\UpdateRolePermissionsAction;
use App\Http\Requests\IdentityAndAccess\DeleteRoleRequest;
use App\Http\Requests\IdentityAndAccess\ListPermissionCatalogRequest;
use App\Http\Requests\IdentityAndAccess\ListRolesRequest;
use App\Http\Requests\IdentityAndAccess\StoreRoleRequest;
use App\Http\Requests\IdentityAndAccess\UpdateRolePermissionsRequest;
use App\Http\Requests\IdentityAndAccess\UpdateRoleRequest;
use App\Http\Requests\IdentityAndAccess\ViewRoleRequest;
use App\Http\Resources\IdentityAndAccess\RoleResource;
use App\Models\IdentityAndAccess\Role;
use App\Queries\IdentityAndAccess\ListRolesQuery;
use App\Services\IdentityAndAccess\PermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class RoleController
{
    public function permissions(ListPermissionCatalogRequest $request, PermissionCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->grouped()])->header('Cache-Control', 'no-store');
    }

    public function index(ListRolesRequest $request, ListRolesQuery $query): AnonymousResourceCollection
    {
        return RoleResource::collection($query->execute($request->validated()));
    }

    public function show(ViewRoleRequest $request, Role $role): RoleResource
    {
        return new RoleResource($role->load('permissions')->loadCount(['users' => fn ($query) => $query->withTrashed()]));
    }

    public function store(StoreRoleRequest $request, CreateRoleAction $action): JsonResponse
    {
        return (new RoleResource($action->execute($request->user(), $request->validated())))
            ->response()->setStatusCode(201);
    }

    public function update(UpdateRoleRequest $request, Role $role, UpdateRoleAction $action): RoleResource
    {
        return new RoleResource($action->execute($request->user(), $role, $request->validated()));
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role, UpdateRolePermissionsAction $action): RoleResource
    {
        return new RoleResource($action->execute($request->user(), $role, $request->validated('permisos'), $request->integer('version')));
    }

    public function destroy(DeleteRoleRequest $request, Role $role, DeleteRoleAction $action): Response
    {
        $action->execute($request->user(), $role, $request->integer('version'));

        return response()->noContent();
    }
}
