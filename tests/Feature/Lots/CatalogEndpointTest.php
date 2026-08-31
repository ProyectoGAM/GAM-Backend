<?php

namespace Tests\Feature\Lots;

use App\Models\Lots\Breed;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

final class CatalogEndpointTest extends LotsTestCase
{
    /** @return array<string, array{string, string}> */
    public static function catalogs(): array
    {
        return ['razas' => ['/razas', 'breeds'], 'categorías' => ['/categorias-mortalidad', 'mortality_categories']];
    }

    // Flujo: crea, consulta, renombra y desactiva catálogos sin eliminar referencias.
    #[DataProvider('catalogs')]
    public function test_catalog_lifecycle_is_versioned_audited_and_idempotent(string $path, string $table): void
    {
        // Preparación: configura permisos y una clave estable para el alta.
        $this->signIn();
        $key = (string) Str::uuid();

        // Requests: crea y repite el alta sin duplicar el catálogo.
        $first = $this->command('POST', $path, ['nombre' => 'Catálogo de prueba'], $key)->assertCreated();
        $replay = $this->command('POST', $path, ['nombre' => 'Catálogo de prueba'], $key)->assertCreated();
        $this->assertSame($first->json(), $replay->json());
        $id = $first->json('data.catalogo.id');
        $this->assertDatabaseCount($table, 1);

        // Mutaciones: renombra y cambia estado, manteniendo identidad y versiones.
        $this->command('PATCH', "{$path}/{$id}", ['nombre' => 'Nombre revisado', 'version' => 1])->assertOk()->assertJsonPath('data.catalogo.version', 2);
        $this->command('PATCH', "{$path}/{$id}", ['estado' => 'inactive', 'version' => 2])->assertOk()->assertJsonPath('data.catalogo.estado', 'inactive');
        $this->command('PATCH', "{$path}/{$id}", ['estado' => 'active', 'version' => 2])->assertConflict();
        $this->getJson('/api/v1'.$path.'?estado=inactive&buscar=revisado')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('activity_log', 3);
    }

    // Flujo: evita duplicados por diferencias de mayúsculas o espacios.
    #[DataProvider('catalogs')]
    public function test_catalog_names_are_unique_after_normalization(string $path, string $table): void
    {
        // Preparación: registra el nombre original.
        $this->signIn();
        $this->command('POST', $path, ['nombre' => 'Ponedoras'])->assertCreated();

        // Request: el nombre equivalente no genera un registro nuevo.
        $this->command('POST', $path, ['nombre' => '  PONEDORAS  '])->assertConflict();
        $this->assertDatabaseCount($table, 1);
    }

    // Flujo: desactivar una raza conserva lotes anteriores, pero bloquea nuevas admisiones.
    public function test_inactive_breed_remains_readable_but_cannot_be_used_for_admission(): void
    {
        // Preparación: vincula un lote a la raza antes de desactivarla.
        $this->signIn();
        $breed = Breed::factory()->create();
        $flock = $this->flock(10, $breed);
        $this->command('PATCH', "/razas/{$breed->id}", ['version' => 1, 'estado' => 'inactive'])->assertOk();

        // Requests: preserva el lote existente y rechaza nuevas aves con esa raza.
        $this->getJson("/api/v1/lotes/{$flock->public_id}")->assertOk()->assertJsonPath('data.raza_id', $breed->id);
        $this->command('POST', '/lotes', [
            'codigo' => 'RAZA-INACTIVA', 'raza_id' => $breed->id, 'origen' => 'Propio', 'cantidad_inicial' => 1,
            'fecha_ingreso' => now()->toDateString(), 'galpon_id' => $flock->poultry_house_id,
        ])->assertConflict();
    }

    // Flujo: los permisos de lectura no habilitan administración de catálogos.
    public function test_catalog_mutations_require_their_own_permissions(): void
    {
        // Preparación: autentica un usuario con acceso sólo de consulta.
        $this->signIn(['breeds.view', 'mortality-categories.view']);

        // Requests: permite listar y prohíbe crear con los mismos permisos.
        $this->getJson('/api/v1/razas')->assertOk();
        $this->getJson('/api/v1/categorias-mortalidad')->assertOk();
        $this->command('POST', '/razas', ['nombre' => 'Prohibido'])->assertForbidden();
        $this->command('POST', '/categorias-mortalidad', ['nombre' => 'Prohibido'])->assertForbidden();
    }
}
