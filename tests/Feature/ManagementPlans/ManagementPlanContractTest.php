<?php

namespace Tests\Feature\ManagementPlans;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class ManagementPlanContractTest extends TestCase
{
    // Flujo: compara el contrato público con todas las rutas del módulo y su idempotencia.
    public function test_openapi_matches_management_plan_routes(): void
    {
        // Preparación: lee el contrato y las rutas registradas.
        $contract = Yaml::parseFile(base_path('contracts/openapi/management-plans.yaml'));
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.management-plans.')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $actual[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
                }
            }
        }

        // Consulta: extrae los métodos publicados y sus parámetros de operación.
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $expected[] = strtoupper($method).' '.$path;
                if ($method !== 'get') {
                    $this->assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $operation['parameters']);
                }
            }
        }

        // Verificación: cada mutación exige clave y cada ruta está documentada.
        $this->assertCount(17, $actual);
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    // Flujo: verifica que todas las referencias internas del contrato se puedan resolver.
    public function test_openapi_has_no_unresolved_local_references(): void
    {
        // Preparación: recorre las referencias del documento OpenAPI.
        $contract = Yaml::parseFile(base_path('contracts/openapi/management-plans.yaml'));
        $references = $this->references($contract);

        // Verificación: cada segmento del puntero existe en el contrato parseado.
        foreach ($references as $reference) {
            $target = $contract;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $target, $reference);
                $target = $target[$segment];
            }
            $this->assertIsArray($target, $reference);
        }
        $this->assertNotEmpty($references);
    }

    /**
     * @param  array<mixed>  $node
     * @return list<string>
     */
    private function references(array $node): array
    {
        $references = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref') {
                $references[] = $value;
            } elseif (is_array($value)) {
                array_push($references, ...$this->references($value));
            }
        }

        return array_values(array_unique($references));
    }
}
