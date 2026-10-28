<?php

namespace App\Events\Inventory;

use App\Enums\Inventory\FeedBalanceStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class FeedBalanceEvaluated implements ShouldDispatchAfterCommit
{
    /** Conserva una evaluación inmutable para que futuras alertas no dependan de una recarga del saldo. */
    public function __construct(
        public int $balanceId,
        public int $recipeId,
        public int $productionUnitId,
        public int $quantityGrams,
        public ?int $minimumQuantityGrams,
        public FeedBalanceStatus $status,
        public string $reason,
        public string $operationId,
    ) {}
}
