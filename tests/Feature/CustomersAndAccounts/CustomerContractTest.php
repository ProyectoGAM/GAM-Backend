<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Models\User;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class CustomerContractTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** El contrato declara las rutas reales y todas las referencias de ambos documentos se resuelven. */
    public function test_contract_matches_routes_and_resolves_cross_document_references(): void
    {
        // Consulta del contrato público y del registro de rutas de Laravel.
        $contract = Yaml::parseFile(base_path('contracts/openapi/customers-and-accounts.yaml'));
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with((string) $route->getName(), 'api.v1.customers.')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $actual[] = $method.' /'.$route->uri();
            }
        }
        $expected = [];
        foreach ($contract['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $expected[] = strtoupper($method).' '.$path;
            }
        }
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        // Referencias externas de Repartos y referencias internas del módulo conjunto.
        foreach (['customers-and-accounts.yaml', 'deliveries.yaml'] as $file) {
            $document = Yaml::parseFile(base_path('contracts/openapi/'.$file));
            array_walk_recursive($document, function ($reference, $key) use ($document): void {
                if ($key !== '$ref') {
                    return;
                }
                [$file, $pointer] = explode('#', $reference, 2);
                $target = $file === '' ? $document : Yaml::parseFile(base_path('contracts/openapi/'.$file));
                foreach (explode('/', ltrim($pointer, '/')) as $segment) {
                    $this->assertArrayHasKey($segment, $target);
                    $target = $target[$segment];
                }
            });
        }
    }

    /** La salida real conserva la allowlist del contrato y no expone IDs internos de actor o hashes. */
    public function test_customer_response_is_within_documented_allowlist(): void
    {
        // Alta autorizada y lectura de una ficha real.
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user, ['api:access']);
        $id = $this->postJson('/api/v1/clientes', ['name' => 'Contrato'], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $data = $this->getJson('/api/v1/clientes/'.$id)->assertOk()->json('data');
        $schema = Yaml::parseFile(base_path('contracts/openapi/customers-and-accounts.yaml'))['components']['schemas']['Customer'];
        $this->assertSame([], array_values(array_diff(array_keys($data), array_keys($schema['properties']))));
        $this->assertArrayNotHasKey('created_by', $data);
        $this->assertArrayNotHasKey('request_hash', $data);
        $this->assertSame('0.000', $data['account']['balance']);
    }
}
