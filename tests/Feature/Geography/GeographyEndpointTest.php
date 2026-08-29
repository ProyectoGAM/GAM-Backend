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

    public function test_returns_401_when_departments_are_requested_without_authentication(): void
    {
        $this->getJson('/api/v1/departamentos')->assertUnauthorized();
    }

    public function test_returns_403_when_user_cannot_manage_geography(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/departamentos', ['name' => 'Montevideo'])
            ->assertForbidden();
    }

    public function test_valid_payload_creates_department_and_locality_with_audit_entries(): void
    {
        $actor = $this->userWithPermissions(['geography.manage']);
        Sanctum::actingAs($actor, ['*']);

        $departmentResponse = $this->postJson('/api/v1/departamentos', [
            'name' => 'Montevideo',
        ])->assertCreated()->assertJsonPath('data.name', 'Montevideo');

        $departmentId = (int) $departmentResponse->json('data.id');

        $this->postJson("/api/v1/departamentos/{$departmentId}/localidades", [
            'name' => 'Santiago Vázquez',
        ])->assertCreated()
            ->assertJsonPath('data.department_id', $departmentId)
            ->assertJsonPath('data.name', 'Santiago Vázquez');

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

    public function test_returns_422_for_a_case_insensitive_duplicate_department_name(): void
    {
        Department::factory()->create(['name' => 'Canelones']);
        Sanctum::actingAs($this->userWithPermissions(['geography.manage']), ['*']);

        $this->postJson('/api/v1/departamentos', ['name' => '  CANELONES  '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name'])
            ->assertJsonPath('errors.name.0', 'El nombre ya está registrado.');
    }

    public function test_view_permission_returns_paginated_departments_and_nested_localities(): void
    {
        $department = Department::factory()->create(['name' => 'Rocha']);
        Locality::factory()->for($department)->create(['name' => 'Chuy']);
        Sanctum::actingAs($this->userWithPermissions(['geography.view']), ['*']);

        $this->getJson('/api/v1/departamentos?search=roch&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Rocha')
            ->assertJsonPath('data.0.localities_count', 1)
            ->assertJsonPath('meta.per_page', 10);

        $this->getJson("/api/v1/departamentos/{$department->getKey()}/localidades")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Chuy')
            ->assertJsonPath('data.0.department.name', 'Rocha');
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
