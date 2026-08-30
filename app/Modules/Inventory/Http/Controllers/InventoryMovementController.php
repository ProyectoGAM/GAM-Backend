<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use App\Modules\Inventory\Application\Actions\AdjustStockToCountAction;
use App\Modules\Inventory\Application\Actions\IssueStockAction;
use App\Modules\Inventory\Application\Actions\ReceiveStockAction;
use App\Modules\Inventory\Application\Actions\RecordStockLossAction;
use App\Modules\Inventory\Application\Actions\ReverseInventoryMovementAction;
use App\Modules\Inventory\Application\Actions\TransferStockAction;
use App\Modules\Inventory\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Http\Requests\IssueStockRequest;
use App\Modules\Inventory\Http\Requests\ReceiveStockRequest;
use App\Modules\Inventory\Http\Requests\RecordStockLossRequest;
use App\Modules\Inventory\Http\Requests\ReverseInventoryMovementRequest;
use App\Modules\Inventory\Http\Requests\TransferStockRequest;
use App\Modules\Inventory\Http\Resources\InventoryMovementResource;
use App\Support\PublicInputMapper;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class InventoryMovementController
{
    public function receive(ReceiveStockRequest $request, ReceiveStockAction $action): JsonResponse
    {
        return $this->created($action->execute(PublicInputMapper::toInternal($request->validated(), 'inventory'), $this->actor($request)));
    }

    public function issue(IssueStockRequest $request, IssueStockAction $action): JsonResponse
    {
        return $this->created($action->execute(PublicInputMapper::toInternal($request->validated(), 'inventory'), $this->actor($request)));
    }

    public function loss(RecordStockLossRequest $request, RecordStockLossAction $action): JsonResponse
    {
        return $this->created($action->execute(PublicInputMapper::toInternal($request->validated(), 'inventory'), $this->actor($request)));
    }

    public function adjust(AdjustStockRequest $request, AdjustStockToCountAction $action): JsonResponse
    {
        return $this->created($action->execute(PublicInputMapper::toInternal($request->validated(), 'inventory'), $this->actor($request)));
    }

    public function transfer(TransferStockRequest $request, TransferStockAction $action): JsonResponse
    {
        return $this->created($action->execute(PublicInputMapper::toInternal($request->validated(), 'inventory'), $this->actor($request)));
    }

    public function reverse(ReverseInventoryMovementRequest $request, InventoryMovement $inventoryMovement, ReverseInventoryMovementAction $action): JsonResponse
    {
        return $this->created($action->execute($inventoryMovement, PublicInputMapper::toInternal($request->validated(), 'inventory'), $this->actor($request)));
    }

    private function created(InventoryMovement $movement): JsonResponse
    {
        return (new InventoryMovementResource($movement))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    private function actor(ReceiveStockRequest|IssueStockRequest|RecordStockLossRequest|AdjustStockRequest|TransferStockRequest|ReverseInventoryMovementRequest $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
