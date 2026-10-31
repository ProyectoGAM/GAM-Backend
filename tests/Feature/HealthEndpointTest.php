<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_the_health_endpoint_returns_a_spanish_success_response(): void
    {
        $this->getJson('/status')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'message' => 'La aplicación está disponible.',
            ]);
    }

    // Verifica el endpoint liviano que consulta la aplicaciÃ³n mÃ³vil para conocer el estado del servidor.
    public function test_the_versioned_api_health_endpoint_is_public_and_reports_success(): void
    {
        // Request: comprueba disponibilidad sin requerir una sesiÃ³n de usuario.
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
