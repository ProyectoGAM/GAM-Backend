<?php

namespace Tests\Unit\Inventory;

use App\Services\Inventory\FeedRecipeCalculator;
use App\ValueObjects\Inventory\RecipeGrams;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FeedRecipeCalculatorTest extends TestCase
{
    // Flujo: acepta entradas y salidas independientes sin sumar ingredientes ni redondear.
    public function test_independent_yield_and_maximum_integer_factor_are_exact(): void
    {
        // Preparación: usa un rendimiento distinto de la suma de entradas.
        $calculator = new FeedRecipeCalculator;

        // Acción: calcula combinaciones y el factor máximo sin utilizar floats.
        $this->assertSame(['factor' => 2, 'quantities' => [1 => 4, 2 => 10]], $calculator->calculate(10, 20, [1 => 2, 2 => 5]));
        $this->assertSame(['factor' => PHP_INT_MAX, 'quantities' => [1 => PHP_INT_MAX]], $calculator->calculate(1, PHP_INT_MAX, [1 => 1]));
    }

    // Flujo: rechaza multiplicaciones desbordadas antes de que PHP las convierta en float.
    public function test_overflow_is_rejected_before_multiplication(): void
    {
        // Preparación: conserva una cantidad válida en el límite representable.
        $quantity = RecipeGrams::from((string) PHP_INT_MAX);

        // Acción: multiplica por un factor que supera ese límite.
        $this->expectException(InvalidArgumentException::class);
        $quantity->multiply(2);
    }

    // Flujo: un número JSON decimal no se admite aunque represente un valor matemáticamente entero.
    public function test_float_input_is_not_silently_normalized(): void
    {
        // Acción: intenta convertir un float a gramos del dominio.
        $this->expectException(InvalidArgumentException::class);
        RecipeGrams::from(2000.0);
    }

    // Flujo: el cálculo reutilizable rechaza un float sin coerción implícita de PHP a entero.
    public function test_calculator_does_not_coerce_fractional_requests_into_valid_multiples(): void
    {
        // Acción: intenta producir un múltiplo sólo válido después de truncar la fracción.
        $this->expectException(InvalidArgumentException::class);
        (new FeedRecipeCalculator)->calculate(13000, 13000.5, [1 => 2000]);
    }

    // Flujo: no convierte un factor fraccionario en un entero mediante el tipado débil de PHP.
    public function test_fractional_multiplication_factor_is_rejected(): void
    {
        // Acción: intenta multiplicar gramos válidos por un factor fraccionario.
        $this->expectException(InvalidArgumentException::class);
        RecipeGrams::from(2000)->multiply(1.5);
    }

    // Flujo: la regla reutilizable impide una composición vacía.
    public function test_empty_composition_is_rejected(): void
    {
        // Acción: intenta calcular una receta sin ingredientes.
        $this->expectException(InvalidArgumentException::class);
        (new FeedRecipeCalculator)->calculate(1, 1, []);
    }

    // Flujo: un rendimiento inválido se rechaza antes de dividir.
    public function test_zero_yield_cannot_trigger_division_by_zero(): void
    {
        // Acción: llama a la regla reutilizable con rendimiento cero.
        $this->expectException(InvalidArgumentException::class);
        (new FeedRecipeCalculator)->calculate(0, 1, [1 => 1]);
    }
}
