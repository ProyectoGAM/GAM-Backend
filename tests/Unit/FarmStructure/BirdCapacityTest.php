<?php

namespace Tests\Unit\FarmStructure;

use App\Modules\FarmStructure\Domain\ValueObjects\BirdCapacity;
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

    // Flujo: evalúa una ocupación superior a la capacidad y verifica el rechazo.
    public function test_rejects_occupancy_higher_than_capacity(): void
    {
        // Preparación: declara la excepción esperada.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La ocupación debe estar entre cero y la capacidad de aves.');

        // Acción: calcula disponibilidad con una ocupación excedida.
        BirdCapacity::fromInt(100)->availableFor(101);
    }
}
