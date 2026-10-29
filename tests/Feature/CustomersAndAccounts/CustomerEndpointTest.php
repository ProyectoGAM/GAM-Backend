<?php

namespace Tests\Feature\CustomersAndAccounts;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\User;
use Database\Seeders\IdentityPermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CustomerEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): User
    {
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }

    /** La identidad fiscal incompleta no impide crear una ficha y su cuenta atómicamente. */
    public function test_creates_customer_without_name_or_document_and_replays_original_snapshot(): void
    {
        // Preparación y alta con datos opcionales vacíos.
        $this->admin();
        $key = (string) Str::uuid();
        $created = $this->postJson('/api/v1/clientes', [], ['Idempotency-Key' => $key])->assertCreated();
        $id = $created->json('data.id');
        $created->assertJsonPath('data.display_name', 'Cliente · '.$id);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customer_accounts', ['balance' => '0.000', 'version' => 0]);
        // Mutación posterior y repetición: el reintento conserva el resultado histórico.
        $this->patchJson('/api/v1/clientes/'.$id, ['name' => 'Nuevo nombre'], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/clientes', [], ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.name', null);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame(2, AuditEntry::query()->where('log_name', 'customers_and_accounts')->count());
    }

    /** Las coordenadas y el documento se validan como parejas también al editar parcialmente. */
    public function test_rejects_incomplete_pairs_and_outside_uruguay_without_persisting(): void
    {
        // Solicitudes inválidas no dejan fichas parciales.
        $this->admin();
        foreach ([['latitude' => '-34.7'], ['document_number' => '123'], ['latitude' => '40', 'longitude' => '-3']] as $data) {
            $this->postJson('/api/v1/clientes', $data, ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        }
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_operations', 0);
    }

    /** Un mismo nombre no es un identificador; búsqueda literal y paginación funcionan con más de cien clientes. */
    public function test_pages_customers_and_searches_literal_percent_without_crossing_suppliers(): void
    {
        // Preparación del catálogo persistente y búsqueda con carácter literal.
        $user = $this->admin();
        Customer::factory()->count(150)->withAccount()->create(['created_by' => $user->id]);
        Customer::factory()->withAccount()->create(['name' => 'Comercio 100%', 'created_by' => $user->id]);
        $this->getJson('/api/v1/clientes?per_page=50&page=3')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 151);
        $this->getJson('/api/v1/clientes?search=100%25')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Comercio 100%');
        $this->assertDatabaseCount('suppliers', 0);
    }

    /** Los filtros y campos no previstos no pueden modificar saldo, actor ni estado. */
    public function test_rejects_unknown_fields_and_same_key_with_different_data(): void
    {
        // Intentos de entrada fuera del contrato.
        $this->admin();
        $key = (string) Str::uuid();
        $this->postJson('/api/v1/clientes', ['name' => 'A'], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/v1/clientes', ['name' => 'B'], ['Idempotency-Key' => $key])->assertConflict();
        $this->postJson('/api/v1/clientes', ['balance' => '10.000'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson('/api/v1/clientes?sort=secret')->assertUnprocessable();
        $this->assertDatabaseCount('customers', 1);
    }

    /** La ficha conserva datos e historia cuando se desactiva. */
    public function test_deactivates_without_deleting_account(): void
    {
        // Alta y transición explícita.
        $user = $this->admin();
        $customer = Customer::factory()->withAccount()->create(['created_by' => $user->id]);
        $this->patchJson('/api/v1/clientes/'.$customer->public_id.'/estado', ['status' => 'inactive'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/v1/clientes/'.$customer->public_id)->assertOk()->assertJsonPath('data.account.balance', '0.000');
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    /** La proyección financiera no aparece por poseer sólo permiso de clientes. */
    public function test_customer_reader_cannot_read_account_or_create_customer(): void
    {
        // Preparación de lector sin permisos de dinero.
        $this->seed(IdentityPermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('customers.view');
        $customer = Customer::factory()->withAccount()->create();
        Sanctum::actingAs($user, ['api:access']);
        $this->getJson('/api/v1/clientes/'.$customer->public_id)->assertOk()->assertJsonMissingPath('data.account');
        $this->getJson('/api/v1/clientes/'.$customer->public_id.'/cuenta-corriente')->assertForbidden();
        $this->postJson('/api/v1/clientes', [], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
    }

    /** Una petición anónima no accede a las fichas. */
    public function test_anonymous_user_cannot_read_customers(): void
    {
        // Consulta sin credenciales.
        $this->getJson('/api/v1/clientes')->assertUnauthorized();
    }
}
