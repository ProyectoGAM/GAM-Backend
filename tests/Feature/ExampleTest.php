<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /** Comprueba que la ruta principal responde correctamente. */
    // Flujo: solicita la página principal y verifica una respuesta exitosa.
    public function test_the_application_returns_a_successful_response(): void
    {
        // Acción: solicita la página principal.
        $response = $this->get('/');

        // Verificación: confirma que la respuesta HTTP es exitosa.
        $response->assertStatus(200);
    }
}
