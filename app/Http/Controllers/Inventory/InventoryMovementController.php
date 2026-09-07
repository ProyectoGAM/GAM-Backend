<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustStockToCountAction;
use App\Actions\Inventory\IssueStockAction;
use App\Actions\Inventory\ReceiveStockAction;
use App\Actions\Inventory\RecordStockLossAction;
use App\Actions\Inventory\ReverseInventoryMovementAction;
use App\Actions\Inventory\TransferStockAction;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\IssueStockRequest;
use App\Http\Requests\Inventory\ReceiveStockRequest;
use App\Http\Requests\Inventory\RecordStockLossRequest;
use App\Http\Requests\Inventory\ReverseInventoryMovementRequest;
use App\Http\Requests\Inventory\TransferStockRequest;
use App\Http\Resources\Inventory\InventoryMovementResource;
use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final readonly class InventoryMovementController
{
    public function receive(ReceiveStockRequest $request, ReceiveStockAction $action): JsonResponse
    {
        return $this->created($action->execute($request->validated(), $this->actor($request)));
    }

    public function issue(IssueStockRequest $request, IssueStockAction $action): JsonResponse
    {
        return $this->created($action->execute($request->validated(), $this->actor($request)));
    }

    public function loss(RecordStockLossRequest $request, RecordStockLossAction $action): JsonResponse
    {
        return $this->created($action->execute($request->validated(), $this->actor($request)));
    }

    public function adjust(AdjustStockRequest $request, AdjustStockToCountAction $action): JsonResponse
    {
        return $this->created($action->execute($request->validated(), $this->actor($request)));
    }

    public function transfer(TransferStockRequest $request, TransferStockAction $action): JsonResponse
    {
        return $this->created($action->execute($request->validated(), $this->actor($request)));
    }

    public function reverse(ReverseInventoryMovementRequest $request, InventoryMovement $inventoryMovement, ReverseInventoryMovementAction $action): JsonResponse
    {
        return $this->created($action->execute($inventoryMovement, $request->validated(), $this->actor($request)));
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
