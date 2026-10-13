<?php

namespace Tests\Feature\Inventory;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class FeedRecipeContractTest extends TestCase
{
    // Flujo: mantiene todas las rutas reales documentadas sin publicar elaboración ni eliminación.
    public function test_openapi_matches_recipe_routes_and_creation_idempotency(): void
    {
        // Preparación: carga el contrato y las rutas reales del catálogo.
        $contract = Yaml::parseFile(base_path('contracts/openapi/feed-recipes.yaml'));
        $actual = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.feed-recipes.')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $actual[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
                }
            }
        }

        // Consulta: extrae los métodos y comprueba la idempotencia sólo del alta.
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ($method !== 'parameters') {
                    $expected[] = strtoupper($method).' '.$path;
                }
            }
        }
        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $contract['paths']['/recetas']['post']['parameters']);
        $this->assertArrayNotHasKey('delete', $contract['paths']['/recetas/{recipe}']);
    }

    // Flujo: valida que las referencias del contrato resuelven dentro del mismo documento.
    public function test_openapi_has_no_unresolved_references(): void
    {
        // Preparación: recorre todos los nodos, incluidas las respuestas y los esquemas.
        $contract = Yaml::parseFile(base_path('contracts/openapi/feed-recipes.yaml'));

        // Consulta: resuelve cada referencia interna como puntero al documento.
        $this->assertReferences($contract, $contract);
    }

    /** @param array<mixed> $node @param array<mixed> $contract */
    private function assertReferences(array $node, array $contract): void
    {
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                $this->assertStringStartsWith('#/', $value);
                $target = $contract;
                foreach (explode('/', substr($value, 2)) as $segment) {
                    $this->assertArrayHasKey($segment, $target);
                    $target = $target[$segment];
                }
                $this->assertIsArray($target);
            } elseif (is_array($value)) {
                $this->assertReferences($value, $contract);
            }
        }
    }
}
