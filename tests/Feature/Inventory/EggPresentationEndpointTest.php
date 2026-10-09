<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Deliveries\Delivery;
use App\Models\Inventory\EggPresentation;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EggPresentationEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function administrator(): User
    {
        // Preparación: autentica al administrador del catálogo global.
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        Sanctum::actingAs($user, ['api:access']);

        return $user;
    }

    // Flujo: crea y edita una presentación real, conserva su identidad y audita las escrituras.
    public function test_administrator_can_create_and_update_units_with_integer_prices(): void
    {
        $this->administrator();
        // Request: verifica que las definiciones importadas no inventan precios.
        $this->getJson('/api/v1/inventario/presentaciones-huevos')->assertOk()
            ->assertJsonPath('meta.locked', false)->assertJsonPath('data.0.currency', 'UYU');
        $this->assertNull(EggPresentation::query()->where('code', 'maple')->value('default_unit_price'));
        // Request: crea una presentación y comprueba su contrato para el repartidor.
        $created = $this->postJson('/api/v1/inventario/presentaciones-huevos', [
            'name' => 'Caja de 45 huevos', 'eggs_per_unit' => 45, 'default_unit_price' => 500,
        ])->assertCreated()->assertJsonPath('data.eggs_per_unit', 45)->assertJsonPath('data.default_unit_price', 500);
        $id = $created->json('data.id');
        // Request: modifica campos sin cambiar la identidad histórica.
        $this->patchJson('/api/v1/inventario/presentaciones-huevos/'.$id, [
            'name' => 'Caja de 60 huevos', 'eggs_per_unit' => 60, 'default_unit_price' => 600,
        ])->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.eggs_per_unit', 60);
        // Verificación: la auditoría conserva creación y modificación.
        $this->assertDatabaseHas((new AuditEntry)->getTable(), ['event' => 'egg_presentation_created']);
        $this->assertDatabaseHas((new AuditEntry)->getTable(), ['event' => 'egg_presentation_updated']);
    }

    // Flujo: cualquier reparto activo bloquea la creación y todos los campos del catálogo.
    public function test_active_delivery_locks_the_whole_catalogue_and_unlocks_after_close(): void
    {
        $this->administrator();
        $unit = EggPresentation::factory()->create(['name' => 'Presentación original', 'eggs_per_unit' => 30, 'default_unit_price' => 100]);
        // Preparación: el reparto pertenece a otro usuario, no al administrador.
        $delivery = Delivery::factory()->create();
        $url = '/api/v1/inventario/presentaciones-huevos/'.$unit->code;
        // Requests: verifica el bloqueo uniforme, incluido precio y creación.
        $this->getJson('/api/v1/inventario/presentaciones-huevos')->assertOk()->assertJsonPath('meta.locked', true);
        foreach ([['name' => 'Otro nombre'], ['eggs_per_unit' => 24], ['default_unit_price' => 90]] as $change) {
            $this->patchJson($url, $change)->assertConflict();
        }
        $this->postJson('/api/v1/inventario/presentaciones-huevos', [
            'name' => 'No se crea', 'eggs_per_unit' => 12, 'default_unit_price' => 50,
        ])->assertConflict();
        $this->assertSame('Presentación original', $unit->fresh()->name);
        $this->assertSame(100, $unit->fresh()->default_unit_price);
        // Mutación de fixture: finaliza el único reparto y confirma el desbloqueo.
        $delivery->forceFill(['status' => 'completed', 'closed_at' => now()])->save();
        $this->patchJson($url, ['default_unit_price' => 90])->assertOk()->assertJsonPath('data.default_unit_price', 90);
    }

    // Flujo: rechaza importes fraccionarios/negativos y evita gestión por usuarios sin rol administrador.
    public function test_prices_and_authorization_are_enforced_by_the_api(): void
    {
        $this->administrator();
        foreach ([100.01, -1] as $price) {
            // Request: intenta guardar un precio fuera del contrato de pesos enteros.
            $this->postJson('/api/v1/inventario/presentaciones-huevos', [
                'name' => 'Presentación inválida', 'eggs_per_unit' => 30, 'default_unit_price' => $price,
            ])->assertUnprocessable()->assertJsonValidationErrors('default_unit_price');
        }
        // Preparación: cambia a un usuario sin permisos administrativos.
        Sanctum::actingAs(User::factory()->create(), ['api:access']);
        $this->getJson('/api/v1/inventario/presentaciones-huevos')->assertForbidden();
        $this->postJson('/api/v1/inventario/presentaciones-huevos', [
            'name' => 'No autorizado', 'eggs_per_unit' => 30, 'default_unit_price' => 100,
        ])->assertForbidden();
    }
}
