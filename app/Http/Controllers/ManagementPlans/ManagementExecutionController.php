<?php

namespace App\Http\Controllers\ManagementPlans;

use App\Actions\ManagementPlans\AdjustMedicineStockAction;
use App\Actions\ManagementPlans\CorrectManagementExecutionAction;
use App\Actions\ManagementPlans\GetMedicineStockAction;
use App\Actions\ManagementPlans\RecordManualPracticeAction;
use App\Actions\ManagementPlans\RecordMedicineApplicationAction;
use App\Actions\ManagementPlans\RecordRationChangeAction;
use App\Actions\ManagementPlans\RecordVaccinationApplicationAction;
use App\Http\Requests\ManagementPlans\AdjustMedicineStockRequest;
use App\Http\Requests\ManagementPlans\CorrectManagementExecutionRequest;
use App\Http\Requests\ManagementPlans\ShowMedicineStockRequest;
use App\Http\Requests\ManagementPlans\StoreManualPracticeRequest;
use App\Http\Requests\ManagementPlans\StoreMedicineApplicationRequest;
use App\Http\Requests\ManagementPlans\StoreRationChangeRequest;
use App\Http\Requests\ManagementPlans\StoreVaccinationApplicationRequest;
use App\Http\Resources\Lots\LotsOperationResource;
use App\Http\Resources\ManagementPlans\MedicineStockMovementResource;
use App\Http\Resources\ManagementPlans\MedicineStockResource;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Medicine;
use Illuminate\Http\JsonResponse;

final readonly class ManagementExecutionController
{
    public function applyVaccination(StoreVaccinationApplicationRequest $request, Flock $flock, RecordVaccinationApplicationAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function changeRation(StoreRationChangeRequest $request, Flock $flock, RecordRationChangeAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function recordManualPractice(StoreManualPracticeRequest $request, Flock $flock, RecordManualPracticeAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function applyMedicine(StoreMedicineApplicationRequest $request, Flock $flock, RecordMedicineApplicationAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function adjustMedicineStock(AdjustMedicineStockRequest $request, Medicine $medicine, AdjustMedicineStockAction $action): JsonResponse
    {
        return (new MedicineStockMovementResource($action->execute($medicine, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }

    public function showMedicineStock(ShowMedicineStockRequest $request, Medicine $medicine, GetMedicineStockAction $action): MedicineStockResource
    {
        return new MedicineStockResource($action->execute($medicine));
    }

    public function correctExecution(CorrectManagementExecutionRequest $request, Flock $flock, string $executionType, string $execution, CorrectManagementExecutionAction $action): JsonResponse
    {
        return (new LotsOperationResource($action->execute($flock, $executionType, $execution, $request->attributesForAction(), $request->actor())))->response()->setStatusCode(201);
    }
}
