<?php

namespace Tests\Unit\FarmStructure;

use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use PHPUnit\Framework\TestCase;

final class PoultryHouseStatusTest extends TestCase
{
    // Flujo: evalúa la transición operativa a mantenimiento y verifica que se permite.
    public function test_allows_operational_house_to_enter_maintenance(): void
    {
        // Acción: consulta si el estado operativo puede pasar a mantenimiento.
        $this->assertTrue(
            PoultryHouseStatus::Operational->canTransitionTo(PoultryHouseStatus::Maintenance),
        );
    }

    // Flujo: evalúa una transición al mismo estado y verifica que se rechaza.
    public function test_rejects_duplicate_status_transition(): void
    {
        // Acción: consulta una transición duplicada de mantenimiento.
        $this->assertFalse(
            PoultryHouseStatus::Maintenance->canTransitionTo(PoultryHouseStatus::Maintenance),
        );
    }

    // Flujo: evalúa las salidas permitidas de un galpón inactivo.
    public function test_inactive_house_can_only_return_to_operational_status(): void
    {
        // Acción 1: comprueba el retorno a estado operativo.
        $this->assertTrue(
            PoultryHouseStatus::Inactive->canTransitionTo(PoultryHouseStatus::Operational),
        );

        // Acción 2: comprueba que no puede pasar directamente a mantenimiento.
        $this->assertFalse(
            PoultryHouseStatus::Inactive->canTransitionTo(PoultryHouseStatus::Maintenance),
        );
    }
}
