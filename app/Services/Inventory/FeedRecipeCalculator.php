<?php

namespace App\Services\Inventory;

use App\ValueObjects\Inventory\RecipeGrams;
use InvalidArgumentException;

final readonly class FeedRecipeCalculator
{
    /**
     * Regla reutilizable por la futura elaboración, sin acceso a base de datos ni stock.
     *
     * @param  array<int, int|string>  $ingredients
     * @return array{factor: int, quantities: array<int, int>}
     */
    public function calculate(mixed $yieldGrams, mixed $requestedGrams, array $ingredients): array
    {
        $yield = RecipeGrams::from($yieldGrams);
        $requested = RecipeGrams::from($requestedGrams);
        if ($requested->value % $yield->value !== 0) {
            throw new InvalidArgumentException('La cantidad solicitada debe ser un múltiplo entero positivo del rendimiento base.');
        }
        if ($ingredients === []) {
            throw new InvalidArgumentException('La receta debe tener al menos un ingrediente.');
        }
        $factor = intdiv($requested->value, $yield->value);
        $quantities = [];
        foreach ($ingredients as $productId => $quantity) {
            $quantities[$productId] = RecipeGrams::from($quantity)->multiply($factor)->value;
        }

        return ['factor' => $factor, 'quantities' => $quantities];
    }
}
