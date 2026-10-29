<?php

namespace Tests\Feature\Lots;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class MortalityMigrationTest extends LotsTestCase
{
    // Flujo: actualiza un esquema previo con mortalidad y permisos sin modificar su auditoría.
    public function test_upgrade_removes_categories_and_preserves_existing_mortality_and_audits(): void
    {
        // Preparación: conserva registros y fotografías auditadas antes de representar el esquema anterior.
        $actor = $this->signIn();
        $flock = $this->flock(10);
        $created = $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 1, 'quantity' => 1, 'notes' => 'Motivo histórico', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated();
        $audits = DB::table('activity_log')->orderBy('id')->get()->toArray();
        $movements = DB::table('flock_movements')->orderBy('id')->get()->toArray();
        $operations = DB::table('flock_operations')->orderBy('id')->get()->toArray();

        // Preparación: reconstruye únicamente la tabla y restricciones que esta migración retira.
        Schema::create('mortality_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->string('status', 20)->default('active');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
        });
        $categoryId = DB::table('mortality_categories')->insertGetId(['name' => 'Causa histórica', 'normalized_name' => 'causa histórica']);
        Schema::table('mortality_records', function (Blueprint $table): void {
            $table->foreignId('mortality_category_id')->nullable()->constrained('mortality_categories')->restrictOnDelete();
        });
        DB::table('mortality_records')->update(['mortality_category_id' => $categoryId]);
        Schema::table('mortality_records', function (Blueprint $table): void {
            $table->foreignId('mortality_category_id')->nullable(false)->change();
        });
        Schema::table('flocks', function (Blueprint $table): void {
            $table->integer('current_quantity')->change();
        });
        DB::statement('ALTER TABLE flocks DROP CONSTRAINT flocks_quantities_check');
        DB::statement('ALTER TABLE flocks ADD CONSTRAINT flocks_quantities_check CHECK (initial_quantity > 0 AND current_quantity >= 0 AND version > 0)');
        DB::statement("ALTER TABLE flocks ADD CONSTRAINT flocks_status_quantity_check CHECK ((status = 'finished') = (current_quantity = 0))");
        $categoryPermission = Permission::findOrCreate('mortality-categories.view', 'web');
        $actor->givePermissionTo($categoryPermission);
        $role = Role::findOrCreate('legacy-catalog-reader', 'web');
        $role->givePermissionTo($categoryPermission);

        // Mutación: aplica la misma migración que actualizará una instalación existente.
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_10_12_083612_allow_negative_mortality_balances_and_remove_categories.php');
        $migration->up();

        // Verificación: elimina catálogo y asignaciones exclusivas, conservando toda la historia anterior.
        $this->assertFalse(Schema::hasTable('mortality_categories'));
        $this->assertFalse(Schema::hasColumn('mortality_records', 'mortality_category_id'));
        $this->assertDatabaseMissing('permissions', ['id' => $categoryPermission->id]);
        $this->assertDatabaseMissing('model_has_permissions', ['permission_id' => $categoryPermission->id]);
        $this->assertDatabaseMissing('role_has_permissions', ['permission_id' => $categoryPermission->id]);
        $this->assertDatabaseHas('mortality_records', ['public_id' => $created->json('data.mortality.id'), 'quantity' => 1, 'notes' => 'Motivo histórico']);
        $this->assertEquals($audits, DB::table('activity_log')->orderBy('id')->get()->toArray());
        $this->assertEquals($movements, DB::table('flock_movements')->orderBy('id')->get()->toArray());
        $this->assertEquals($operations, DB::table('flock_operations')->orderBy('id')->get()->toArray());

        // Request: la instalación actualizada puede registrar un saldo negativo sin finalizar.
        $this->command('POST', "/flocks/{$flock->public_id}/mortalities", [
            'version' => 2, 'quantity' => 10, 'notes' => 'Nuevo recuento', 'occurred_at' => now()->startOfSecond()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.flock.current_quantity', -1)->assertJsonPath('data.flock.status', 'active');
    }
}
