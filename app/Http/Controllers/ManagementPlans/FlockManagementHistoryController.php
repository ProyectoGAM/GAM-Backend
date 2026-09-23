<?php

namespace App\Http\Controllers\ManagementPlans;

use App\Http\Requests\ManagementPlans\ListFlockManagementHistoryRequest;
use App\Http\Resources\ManagementPlans\ManagementHistoryResource;
use App\Models\Lots\Flock;
use App\Queries\ManagementPlans\ListFlockManagementHistoryQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class FlockManagementHistoryController
{
    public function index(ListFlockManagementHistoryRequest $request, Flock $flock, ListFlockManagementHistoryQuery $query): AnonymousResourceCollection
    {
        return ManagementHistoryResource::collection($query->execute($flock, $request->attributesForAction()));
    }
}
