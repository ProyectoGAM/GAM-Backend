<?php

namespace Tests\Feature\Lots;

use App\Http\Resources\Lots\WeighingResource;
use App\Http\Resources\Lots\WeighingSettingsResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class WeighingContractTest extends TestCase
{
    // Flujo: compara las rutas públicas de Pesajes con el contrato OpenAPI dedicado.
    public function test_openapi_matches_registered_weighing_routes(): void
    {
        // Preparación: carga el YAML y filtra sólo nombres api.v1.weighings.
        $contract = Yaml::parseFile(base_path('contracts/openapi/weighings.yaml'));
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.weighings.')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $actual[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
                }
            }
        }

        // Consulta: obtiene cada método publicado del contrato.
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $expected[] = strtoupper($method).' '.$path;
                if ($method !== 'get') {
                    $this->assertContains(['$ref' => '#/components/parameters/IdempotencyKey'], $operation['parameters']);
                }
            }
        }

        // Verificación: no hay rutas públicas sin documentar ni operaciones sobrantes.
        $this->assertCount(8, $actual);
        $this->assertEqualsCanonicalizing($expected, $actual);
    }

    // Flujo: mantiene sincronizadas las claves de los Resources con los esquemas públicos.
    public function test_openapi_resources_have_declared_fields(): void
    {
        // Preparación: serializa recursos representativos sin consultar la base.
        $contract = Yaml::parseFile(base_path('contracts/openapi/weighings.yaml'));
        $request = Request::create('/api/v1/pesajes');
        $weighing = (new WeighingResource([
            'id' => '01J00000000000000000000000',
            'flock_id' => '01J00000000000000000000001',
            'poultry_house_id' => 1,
            'production_unit_id' => 1,
            'mode' => 'individual',
            'occurred_at' => '2026-01-01T00:00:00+00:00',
            'unit' => 'g',
            'captured_unit' => 'g',
            'notes' => null,
            'stage' => null,
            'expected_range' => null,
            'reference' => null,
            'represented_bird_count' => 1,
            'total_weight_g' => '20.0',
            'average_weight_g' => '20.000000',
            'total_weight' => ['value' => '20.0', 'unit' => 'g'],
            'average_weight' => ['value' => '20.000000', 'unit' => 'g'],
            'outside_expected_range' => false,
            'version' => 1,
            'created_by' => 1,
            'measurements' => [],
        ]))->resolve($request);
        $settings = (new WeighingSettingsResource([
            'adult_from_week' => 18,
            'chick_min_weight_g' => '10.0',
            'chick_max_weight_g' => '100.0',
            'adult_min_weight_g' => '100.0',
            'adult_max_weight_g' => '3000.0',
            'unit' => 'g',
            'captured_unit' => 'g',
            'version' => 1,
        ]))->resolve($request);

        // Verificación: cada campo serializado figura en el esquema correspondiente.
        $this->assertEqualsCanonicalizing(array_keys($contract['components']['schemas']['Weighing']['properties']), array_keys($weighing));
        $this->assertEqualsCanonicalizing(array_keys($contract['components']['schemas']['ReferenceSettings']['properties']), array_keys($settings));
    }

    // Flujo: evita referencias internas OpenAPI que apunten a componentes inexistentes.
    public function test_openapi_has_no_unresolved_references(): void
    {
        // Preparación: recorre el contrato parseado de Pesajes.
        $contract = Yaml::parseFile(base_path('contracts/openapi/weighings.yaml'));
        $references = $this->references($contract);

        // Consulta: resuelve cada puntero local por segmentos.
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

    // Flujo: documenta autenticación personal y transporte estricto de sesiones compartidas.
    public function test_openapi_declares_personal_and_shared_security_transports(): void
    {
        // Preparación: carga los esquemas de seguridad del contrato de Pesajes.
        $contract = Yaml::parseFile(base_path('contracts/openapi/weighings.yaml'));

        // Verificación: declara cookie personal, Bearer y ambos transportes compartidos.
        $this->assertSame(
            [
                'bearerAuth',
                'sanctumCookie',
                'sharedDeviceHeader',
                'sharedDeviceCookie',
                'sharedSessionHeader',
                'csrf',
                'IdempotencyKey',
            ],
            array_keys($contract['components']['securitySchemes']),
        );
        $this->assertSame(
            [
                ['bearerAuth' => []],
                ['sanctumCookie' => []],
                ['bearerAuth' => [], 'sharedDeviceHeader' => [], 'sharedSessionHeader' => []],
                ['sanctumCookie' => [], 'sharedDeviceCookie' => [], 'sharedSessionHeader' => []],
            ],
            $contract['security'],
        );
    }

    // Flujo: condiciona los decimales de una corrección a la unidad explícita o heredada.
    public function test_openapi_models_conditional_correction_units(): void
    {
        // Preparación: carga las variantes de corrección y sus referencias de medición.
        $contract = Yaml::parseFile(base_path('contracts/openapi/weighings.yaml'));
        $schemas = $contract['components']['schemas'];
        $correction = $schemas['CorrectWeighing'];

        // Verificación: expone gramos, kilogramos y la unidad histórica omitida.
        $this->assertSame([
            ['$ref' => '#/components/schemas/CorrectWeighingGrams'],
            ['$ref' => '#/components/schemas/CorrectWeighingKilograms'],
            ['$ref' => '#/components/schemas/CorrectWeighingInheritedUnit'],
        ], $correction['oneOf']);
        $this->assertStringContainsString('unidad capturada', $correction['description']);

        // Consulta: obtiene la forma de medición de cada variante explícita.
        $grams = $schemas['CorrectWeighingGrams'];
        $kilograms = $schemas['CorrectWeighingKilograms'];
        $inherited = $schemas['CorrectWeighingInheritedUnit'];

        // Verificación: una unidad explícita exige sus decimales correspondientes.
        $this->assertContains('unit', $grams['required']);
        $this->assertSame(['g'], $grams['properties']['unit']['enum']);
        $this->assertSame([
            ['$ref' => '#/components/schemas/IndividualMeasurementGrams'],
            ['$ref' => '#/components/schemas/GroupMeasurementGrams'],
        ], $grams['properties']['measurements']['items']['oneOf']);
        $this->assertContains('unit', $kilograms['required']);
        $this->assertSame(['kg'], $kilograms['properties']['unit']['enum']);
        $this->assertSame([
            ['$ref' => '#/components/schemas/IndividualMeasurementKilograms'],
            ['$ref' => '#/components/schemas/GroupMeasurementKilograms'],
        ], $kilograms['properties']['measurements']['items']['oneOf']);

        // Verificación: omitir unit no inventa una unidad y permite heredarla del pesaje.
        $this->assertArrayNotHasKey('unit', $inherited['properties']);
        $this->assertArrayHasKey('anyOf', $inherited['properties']['measurements']['items']);
        $this->assertStringContainsString('hereda la unidad', $inherited['description']);
    }

    /** @param array<mixed> $node @return list<string> */
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
