<?php

namespace Tests\Feature\Lots;

use App\Modules\Lots\Http\Resources\EggCollectionResource;
use App\Modules\Lots\Http\Resources\FlockMovementResource;
use App\Modules\Lots\Http\Resources\FlockResource;
use App\Modules\Lots\Http\Resources\LotsCatalogResource;
use App\Modules\Lots\Http\Resources\MortalityResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class LotsContractTest extends TestCase
{
    /** @return array<string, mixed> */
    private function contract(): array
    {
        return Yaml::parseFile(base_path('contracts/openapi/lots.yaml'));
    }

    // Flujo: compara el contrato publicado con todos los métodos y rutas registrados.
    public function test_openapi_matches_all_registered_lots_endpoints(): void
    {
        // Preparación: carga el YAML real y las rutas de la aplicación.
        $contract = $this->contract();
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            $routeName = (string) $route->getName();
            if (! str_starts_with($routeName, 'api.v1.lots.') && ! str_starts_with($routeName, 'api.v1.egg-stock.')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $actual[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
                }
            }
        }

        // Consulta: cada operación publicada debe existir y no debe haber borrados.
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $expected[] = strtoupper($method).' '.$path;
                $this->assertNotSame('delete', $method);
                if ($method !== 'get') {
                    $this->assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $operation['parameters']);
                }
            }
        }
        $this->assertCount(37, $actual);
        $this->assertEqualsCanonicalizing($expected, $actual);
        $this->assertSame([['bearerAuth' => []]], $contract['security']);
        $this->assertSame('09 — Producción y stock de huevos', $contract['info']['title']);
    }

    // Flujo: comprueba que los Resources no introduzcan campos distintos del contrato público.
    public function test_response_schemas_match_the_actual_public_resource_fields(): void
    {
        // Preparación: elige los Resources de salida sin consultar ni mutar la base.
        $schemas = $this->contract()['components']['schemas'];
        $resources = [
            'Flock' => FlockResource::class,
            'Movement' => FlockMovementResource::class,
            'Mortality' => MortalityResource::class,
            'EggCollection' => EggCollectionResource::class,
            'Catalog' => LotsCatalogResource::class,
        ];
        $request = Request::create('/api/v1/lotes');

        // Consulta: serializa sólo las claves y las compara con sus esquemas.
        foreach ($resources as $name => $resource) {
            $fields = (new $resource(['before' => [], 'after' => []]))->resolve($request);
            $this->assertEqualsCanonicalizing(array_keys($schemas[$name]['properties']), array_keys($fields), $name);
        }
    }

    // Flujo: todas las referencias internas del YAML deben apuntar a componentes existentes.
    public function test_openapi_has_no_unresolved_component_references(): void
    {
        // Preparación: carga y recorre las referencias del contrato.
        $contract = $this->contract();
        $references = $this->references($contract);
        $this->assertNotEmpty($references);

        // Consulta: resuelve cada puntero sin depender de servicios externos.
        foreach ($references as $reference) {
            $this->assertStringStartsWith('#/', $reference);
            $target = $contract;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $target, $reference);
                $target = $target[$segment];
            }
            $this->assertIsArray($target, $reference);
        }
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
