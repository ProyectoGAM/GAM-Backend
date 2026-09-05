<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class MedicineContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: verifica que el contrato sólo publique las dos operaciones autorizadas.
    public function test_contract_matches_registered_routes_and_resolves_references(): void
    {
        // Preparación: carga el contrato publicado y las rutas reales del catálogo.
        $contract = Yaml::parseFile(base_path('contracts/openapi/medication.yaml'));
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.medicines.')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
            }
        }

        // Consulta: compara operaciones, seguridad y referencias internas.
        $expected = [];
        foreach ($contract['paths'] as $uri => $operations) {
            foreach ($operations as $method => $operation) {
                $expected[] = strtoupper($method).' '.$uri;
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame(['GET /medicamentos', 'POST /medicamentos'], $actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([['bearerAuth' => []]], $contract['security']);
        $references = [];
        array_walk_recursive($contract, static function (mixed $value, string|int $key) use (&$references): void {
            if ($key === '$ref') {
                $references[] = $value;
            }
        });
        $this->assertNotEmpty($references);
        foreach ($references as $reference) {
            $this->assertStringStartsWith('#/', $reference);
            $target = $contract;
            foreach (explode('/', substr($reference, 2)) as $segment) {
                $this->assertArrayHasKey($segment, $target);
                $target = $target[$segment];
            }
        }
    }

    // Flujo: la respuesta HTTP real coincide con la representación documentada.
    public function test_real_create_and_list_responses_match_public_schema(): void
    {
        // Preparación: construye referencias autorizadas y lee el esquema de salida.
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($admin, ['*']);
        $supplier = Supplier::factory()->create();
        $contract = Yaml::parseFile(base_path('contracts/openapi/medication.yaml'));
        $schema = $contract['components']['schemas']['Medicine'];

        // Acción: crea usando exactamente los campos del contrato de alta.
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/medicamentos', [
            'nombre' => str_repeat('A', 160), 'descripcion' => str_repeat('D', 5000), 'proveedor_id' => $supplier->id,
        ])->assertCreated();
        $data = $response->json('data');
        $keys = array_keys($data);
        $expected = array_keys($schema['properties']);
        sort($keys);
        sort($expected);
        $this->assertSame($expected, $keys);
        $this->assertMatchesRegularExpression('/'.$schema['properties']['id']['pattern'].'/', $data['id']);
        foreach (['proveedor', 'registrado_por'] as $field) {
            $this->assertSame($schema['properties'][$field]['required'], array_keys($data[$field]));
        }

        // Consulta: el listado entrega la misma ficha completa y metadatos de paginación.
        $this->getJson('/api/v1/medicamentos')->assertOk()->assertJsonPath('data.0', $data)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total']]);
    }
}
