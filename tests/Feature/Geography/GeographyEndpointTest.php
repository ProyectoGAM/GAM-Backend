<?php

namespace Tests\Feature\Geography;

use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class GeographyEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    // Flujo: solicita departamentos sin sesión y verifica la respuesta no autorizada.
    public function test_returns_401_when_departamentos_are_requested_without_authentication(): void
    {
        // Acción: consulta el catálogo de departamentos sin autenticación.
        $this->getJson('/api/v1/departamentos')->assertUnauthorized();
    }

    // Flujo: autentica un usuario sin permiso y verifica que no puede crear geografías.
    public function test_returns_403_when_user_cannot_manage_geography(): void
    {
        // Preparación: autentica un usuario sin permiso de gestión.
        Sanctum::actingAs(User::factory()->create(), ['*']);

        // Acción: intenta crear un departamento.
        $this->postJson('/api/v1/departamentos', ['nombre' => 'Montevideo'])
            ->assertForbidden();
    }

    // Flujo: crea un departamento y una localidad; verifica persistencia y auditoría.
    public function test_valid_payload_creates_department_and_locality_with_audit_entries(): void
    {
        // Preparación: autentica al actor con permiso de gestión.
        $actor = $this->userWithPermissions(['geography.manage']);
        Sanctum::actingAs($actor, ['*']);

        // Acción 1: crea el departamento.
        $departmentResponse = $this->postJson('/api/v1/departamentos', [
            'nombre' => 'Montevideo',
        ])->assertCreated()->assertJsonPath('data.nombre', 'Montevideo');

        $departmentId = (int) $departmentResponse->json('data.id');

        // Acción 2: crea una localidad dentro del departamento.
        $this->postJson("/api/v1/departamentos/{$departmentId}/localidades", [
            'nombre' => 'Santiago Vázquez',
        ])->assertCreated()
            ->assertJsonPath('data.departamento_id', $departmentId)
            ->assertJsonPath('data.nombre', 'Santiago Vázquez');

        // Verificación: confirma registros normalizados y sus auditorías.
        $this->assertDatabaseHas('departments', [
            'id' => $departmentId,
            'normalized_name' => 'montevideo',
        ]);
        $this->assertDatabaseHas('localities', [
            'department_id' => $departmentId,
            'normalized_name' => 'santiago vázquez',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'department_created',
            'causer_id' => $actor->getKey(),
        ]);
        $this->assertDatabaseHas('activity_log', [
            'event' => 'locality_created',
            'causer_id' => $actor->getKey(),
        ]);
    }

    // Flujo: crea un nombre duplicado con distinta capitalización y verifica la validación.
    public function test_returns_422_for_a_case_insensitive_duplicate_department_name(): void
    {
        // Preparación: persiste el departamento original y autentica al gestor.
        Department::factory()->create(['name' => 'Canelones']);
        Sanctum::actingAs($this->userWithPermissions(['geography.manage']), ['*']);

        // Acción: intenta registrar el mismo nombre con espacios y mayúsculas.
        $this->postJson('/api/v1/departamentos', ['nombre' => '  CANELONES  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre'])
            ->assertJsonPath('errors.nombre.0', 'El nombre ya está registrado.');
    }

    // Flujo: consulta departamentos y localidades con permiso, búsqueda y paginación.
    public function test_view_permission_returns_paginated_departamentos_and_nested_localidades(): void
    {
        // Preparación: crea la jerarquía geográfica y autentica al lector.
        $departamento = Department::factory()->create(['name' => 'Rocha']);
        Locality::factory()->for($departamento)->create(['name' => 'Chuy']);
        Sanctum::actingAs($this->userWithPermissions(['geography.view']), ['*']);

        // Acción 1: lista departamentos aplicando búsqueda y paginación.
        $this->getJson('/api/v1/departamentos?buscar=roch&por_pagina=10')
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'Rocha')
            ->assertJsonPath('data.0.localidades_count', 1)
            ->assertJsonPath('meta.per_page', 10);

        // Acción 2: consulta las localidades anidadas del departamento.
        $this->getJson("/api/v1/departamentos/{$departamento->getKey()}/localidades")
            ->assertOk()
            ->assertJsonPath('data.0.nombre', 'Chuy')
            ->assertJsonPath('data.0.departamento.nombre', 'Rocha');
    }

    /** @param list<string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
