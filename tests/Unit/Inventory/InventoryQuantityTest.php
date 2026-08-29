<?php

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\ValueObjects\InventoryQuantity;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InventoryQuantityTest extends TestCase
{
    // Flujo: normaliza una cantidad decimal y verifica seis posiciones de precisión.
    public function test_formats_decimal_values_with_six_places(): void
    {
        // Acción: construye una cantidad de kilogramos con una fracción.
        $quantity = InventoryQuantity::from('12.5', BaseUnit::Kilogram, false);

        // Verificación: confirma la representación decimal normalizada.
        $this->assertSame('12.500000', $quantity->toString());
    }

    // Flujo: intenta usar más de seis decimales y verifica el rechazo.
    public function test_rejects_more_than_six_decimal_places(): void
    {
        // Preparación: declara la excepción de precisión esperada.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hasta seis decimales');

        // Acción: construye una cantidad con precisión excesiva.
        InventoryQuantity::from('1.1234567', BaseUnit::Kilogram, false);
    }

    // Flujo: intenta fraccionar una unidad indivisible y verifica el rechazo.
    public function test_rejects_fractional_indivisible_units(): void
    {
        // Preparación: declara la excepción de unidad esperada.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no permite fracciones');

        // Acción: construye una cantidad fraccionaria de unidades.
        InventoryQuantity::from('1.5', BaseUnit::Unit, false);
    }

    // Flujo: suma dos cantidades decimales y verifica precisión exacta sin floats.
    public function test_addition_keeps_exact_decimal_precision(): void
    {
        // Acción 1: construye la primera cantidad.
        $one = InventoryQuantity::from('0.1', BaseUnit::Kilogram);

        // Acción 2: construye la segunda cantidad.
        $two = InventoryQuantity::from('0.2', BaseUnit::Kilogram);

        // Verificación: confirma la suma exacta con seis decimales.
        $this->assertSame('0.300000', $one->plus($two)->toString());
    }
}
