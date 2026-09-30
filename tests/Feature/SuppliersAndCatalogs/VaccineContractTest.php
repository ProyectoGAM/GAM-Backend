<?php

namespace Tests\Feature\SuppliersAndCatalogs;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class VaccineContractTest extends TestCase
{
    // Flujo: publica exactamente las cinco rutas administrativas del contrato de vacunas.
    public function test_contract_matches_registered_vaccine_routes_and_internal_references(): void
    {
        // Preparación: carga el contrato y obtiene las rutas reales con su prefijo público.
        $contract = Yaml::parseFile(base_path('contracts/openapi/vaccination.yaml'));
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.vaccines.')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
            }
        }

        // Verificación: compara métodos, URIs y todas las referencias locales declaradas.
        $expected = [];
        foreach ($contract['paths'] as $uri => $operations) {
            foreach ($operations as $method => $operation) {
                $expected[] = strtoupper($method).' '.$uri;
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame(['GET /vacunas', 'GET /vacunas/{vaccine}', 'PATCH /vacunas/{vaccine}', 'PATCH /vacunas/{vaccine}/estado', 'POST /vacunas'], $actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([['bearerAuth' => []]], $contract['security']);
        $this->assertSame('/api/v1', $contract['servers'][0]['url']);
        $this->assertSame(1, $contract['components']['schemas']['CreateVaccine']['properties']['sku']['minLength']);
        $this->assertSame(100, $contract['components']['parameters']['PerPage']['schema']['maximum']);
        $this->assertSame(50, $contract['components']['parameters']['PerPage']['schema']['default']);
        $this->assertStringContainsString('ADMIN', $contract['paths']['/vacunas']['post']['description']);
        $this->assertStringContainsString('Idempotency-Key', $contract['paths']['/vacunas']['post']['description']);
        $this->assertStringContainsString('proveedor', $contract['paths']['/vacunas']['post']['description']);
        $this->assertArrayHasKey('404', $contract['paths']['/vacunas']['post']['responses']);
    }
}
