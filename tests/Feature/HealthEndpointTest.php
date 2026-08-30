<?php

namespace Tests\Feature;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_the_health_endpoint_returns_a_spanish_success_response(): void
    {
        $this->getJson('/estado')
            ->assertOk()
            ->assertJson([
                'estado' => 'ok',
                'message' => 'La aplicación está disponible.',
            ]);
    }
}
