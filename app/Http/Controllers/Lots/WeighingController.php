<?php

namespace App\Http\Controllers\Lots;

use App\Actions\Lots\CorrectWeighingAction;
use App\Actions\Lots\RecordWeighingAction;
use App\Actions\Lots\SaveWeighingReferenceSettingsAction;
use App\Http\Requests\Lots\CorrectWeighingRequest;
use App\Http\Requests\Lots\ListWeighingsRequest;
use App\Http\Requests\Lots\StoreWeighingRequest;
use App\Http\Requests\Lots\UpdateWeighingSettingsRequest;
use App\Http\Requests\Lots\ViewWeighingRequest;
use App\Http\Requests\Lots\ViewWeighingSettingsRequest;
use App\Http\Requests\Lots\WeighingDistributionRequest;
use App\Http\Requests\Lots\WeighingEvolutionRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Http\Resources\Lots\WeighingResource;
use App\Models\Lots\Flock;
use App\Models\Lots\Weighing;
use App\Models\Lots\WeighingReferenceSettings;
use App\Queries\Lots\GetWeighingDistributionQuery;
use App\Queries\Lots\GetWeighingEvolutionQuery;
use App\Queries\Lots\ListWeighingsQuery;
use App\Services\Lots\WeighingPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class WeighingController
{
    public function index(ListWeighingsRequest $request, ListWeighingsQuery $query): AnonymousResourceCollection
    {
        return WeighingResource::collection($query->execute($request->validated()));
    }

    public function store(StoreWeighingRequest $request, RecordWeighingAction $action): JsonResponse
    {
        $data = $request->attributesForAction();
        $flock = Flock::query()->where('public_id', $data['flock_id'])->firstOrFail();

        return (new LotsOperationResource($action->execute($flock, $data, $request->actor())))->response()->setStatusCode(201);
    }

    public function show(ViewWeighingRequest $request, Weighing $pesaje, WeighingPresenter $presenter): WeighingResource
    {
        return new WeighingResource($presenter->weighing($pesaje));
    }

    public function update(CorrectWeighingRequest $request, Weighing $pesaje, CorrectWeighingAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($pesaje, $request->attributesForAction(), $request->actor()));
    }

    public function distribution(WeighingDistributionRequest $request, Weighing $pesaje, GetWeighingDistributionQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($pesaje, (string) ($request->validated()['unit'] ?? 'g'))]);
    }

    public function evolution(WeighingEvolutionRequest $request, GetWeighingEvolutionQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->execute($request->validated())]);
    }

    public function settings(ViewWeighingSettingsRequest $request, WeighingPresenter $presenter): JsonResponse
    {
        $settings = WeighingReferenceSettings::query()->first();

        return response()->json(['data' => $presenter->settings($settings)]);
    }

    public function updateSettings(UpdateWeighingSettingsRequest $request, SaveWeighingReferenceSettingsAction $action): LotsOperationResource
    {
        return new LotsOperationResource($action->execute($request->attributesForAction(), $request->actor()));
    }
}
