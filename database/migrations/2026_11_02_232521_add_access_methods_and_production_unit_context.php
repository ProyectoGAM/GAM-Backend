<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Incorpora modalidades permanentes y contexto de UP sin inferir asignaciones históricas.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('access_method', 32)->default('email_password');
            $table->foreignId('production_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('operation_pin_hash')->nullable();
            $table->index(['production_unit_id', 'access_method', 'pin_enabled']);
        });
        // Las cuentas existentes conservan correo/contraseña; su PIN previo pasa a ser operativo.
        DB::table('users')->update(['operation_pin_hash' => DB::raw('pin_hash'), 'pin_hash' => null, 'pin_enabled' => false]);
        foreach (['shared_devices', 'shared_device_pairing_codes', 'auth_sessions', 'report_exports', 'report_presets', 'flock_operations', 'egg_stock_commands', 'customer_operations'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->foreignId('production_unit_id')->nullable()->constrained()->restrictOnDelete();
                $table->index(['production_unit_id', 'created_at']);
            });
        }
        // Una vinculación antigua sin UP requiere revinculación explícita, nunca una asignación inferida.
        DB::table('shared_devices')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DB::table('shared_device_pairing_codes')->update(['expires_at' => now()]);
        DB::table('auth_sessions')->where('kind', 'shared_user')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'production_unit_required']);
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_access_method_check CHECK (
            (access_method = 'email_password' AND email IS NOT NULL AND password IS NOT NULL AND production_unit_id IS NULL AND pin_hash IS NULL AND NOT pin_enabled)
            OR (access_method = 'device_pin' AND email IS NULL AND password IS NULL AND production_unit_id IS NOT NULL AND pin_hash IS NOT NULL AND operation_pin_hash IS NULL))");
        DB::statement("CREATE OR REPLACE FUNCTION preserve_user_access_method() RETURNS trigger LANGUAGE plpgsql AS '
            BEGIN IF NEW.access_method IS DISTINCT FROM OLD.access_method THEN
                RAISE EXCEPTION ''La modalidad de acceso no puede cambiar.'';
            END IF; RETURN NEW; END'");
        DB::statement('CREATE TRIGGER users_preserve_access_method BEFORE UPDATE ON users FOR EACH ROW EXECUTE FUNCTION preserve_user_access_method()');
        DB::table('roles')->insertOrIgnore(['name' => 'unassigned', 'guard_name' => 'web', 'label' => 'Sin rol', 'description' => 'Sin permisos ni acceso a la aplicación', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Revierte el esquema sólo si las cuentas existentes siguen siendo representables.
     */
    public function down(): void
    {
        // Volver atrás exige clasificar las cuentas PIN; no fabricar correos ni contraseñas.
        if (DB::table('users')->where('access_method', 'device_pin')->exists()) {
            throw new RuntimeException('No se puede revertir mientras existan cuentas con acceso PIN.');
        }
        DB::statement('DROP TRIGGER IF EXISTS users_preserve_access_method ON users');
        DB::statement('DROP FUNCTION IF EXISTS preserve_user_access_method()');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_access_method_check');
        foreach (['auth_sessions', 'shared_device_pairing_codes', 'shared_devices', 'report_exports', 'report_presets', 'flock_operations', 'egg_stock_commands', 'customer_operations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('production_unit_id'));
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['production_unit_id', 'access_method', 'pin_enabled']);
            $table->dropConstrainedForeignId('production_unit_id');
            $table->dropColumn(['access_method', 'operation_pin_hash']);
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
