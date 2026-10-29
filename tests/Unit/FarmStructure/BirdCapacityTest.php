<?php

namespace Tests\Unit\FarmStructure;

use App\ValueObjects\FarmStructure\BirdCapacity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BirdCapacityTest extends TestCase
{
    // Flujo: crea una capacidad válida y calcula la disponibilidad restante.
    public function test_returns_available_capacity_for_valid_occupancy(): void
    {
        // Acción: construye la capacidad física del galpón.
        $capacity = BirdCapacity::fromInt(100);

        // Verificación: confirma capacidad, ocupación aceptada y disponibilidad.
        $this->assertSame(100, $capacity->value());
        $this->assertTrue($capacity->supportsOccupancy(75));
        $this->assertSame(25, $capacity->availableFor(75));
    }

    // Flujo: intenta crear capacidad cero y verifica el rechazo del valor inválido.
    public function test_rejects_capacity_that_is_not_positive(): void
    {
        // Preparación: declara la excepción esperada.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La capacidad de aves debe ser mayor que cero.');

        // Acción: construye una capacidad no positiva.
        BirdCapacity::fromInt(0);
    }

    // Flujo: admite sobreocupación y muestra disponibilidad cero sin alterar el límite.
    public function test_over_capacity_occupancy_has_zero_availability(): void
    {
        // Acción: evalúa un galpón con una ave de exceso.
        $capacity = BirdCapacity::fromInt(100);

        // Verificación: conserva el umbral y evita disponibilidad negativa.
        $this->assertSame(0, $capacity->availableFor(101));
        $this->assertFalse($capacity->supportsOccupancy(101));
        $this->assertSame(100, $capacity->value());
    }

    // Flujo: sigue rechazando una ocupación física negativa.
    public function test_rejects_negative_occupancy(): void
    {
        // Preparación: declara la excepción esperada.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ocupación no puede ser negativa.');

        // Acción: calcula disponibilidad con una ocupación inválida.
        BirdCapacity::fromInt(100)->availableFor(-1);
    }
}
