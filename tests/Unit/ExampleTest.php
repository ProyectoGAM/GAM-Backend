<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    // Flujo: evalúa un valor verdadero y verifica la aserción básica.
    public function test_that_true_is_true(): void
    {
        // Acción: ejecuta la comprobación del valor.
        $this->assertTrue(true);
    }
}
