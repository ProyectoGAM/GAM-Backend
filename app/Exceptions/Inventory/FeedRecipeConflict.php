<?php

namespace App\Exceptions\Inventory;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FeedRecipeConflict extends DomainException implements ShouldntReport
{
    /** @var array<string, mixed> */
    private array $details = [];

    /** @param list<array{product_id:int, name:string, quantity_grams:string}> $shortages */
    public static function stockConfirmationRequired(string $confirmation, array $shortages): self
    {
        $exception = new self('El stock de ingredientes no alcanza. Confirma los faltantes para elaborar la ración.');
        $exception->details = [
            'code' => 'FEED_STOCK_CONFIRMATION_REQUIRED',
            'confirmacion_faltante' => $confirmation,
            'faltantes' => array_map(static fn (array $shortage): array => [
                'producto_id' => $shortage['product_id'],
                'nombre' => $shortage['name'],
                'cantidad_faltante_g' => $shortage['quantity_grams'],
            ], $shortages),
        ];

        return $exception;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), ...$this->details], Response::HTTP_CONFLICT);
    }
}
