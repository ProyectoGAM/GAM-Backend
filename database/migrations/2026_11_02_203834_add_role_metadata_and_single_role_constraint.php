<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->string('label', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
        });
        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->unique(['model_type', 'model_id'], 'model_has_roles_single_role_unique');
        });
        DB::statement("ALTER TABLE model_has_permissions ADD CONSTRAINT no_direct_user_permissions CHECK (model_type <> 'App\\Models\\User')");

        // Actualiza identidades existentes sin modificar las migraciones históricas.
        $names = [
            'admin.dashboard.view' => 'can_view_admin_dashboard',
            'audit.view' => 'can_view_audit',
            'customers.view' => 'can_view_customers',
            'customers.manage' => 'can_manage_customers',
            'customer-accounts.view' => 'can_view_customer_accounts',
            'customer-payments.record' => 'can_record_customer_payments',
            'customer-accounts.adjust' => 'can_adjust_customer_accounts',
            'customer-accounts.reverse' => 'can_reverse_customer_accounts',
            'flocks.view' => 'can_view_flocks',
            'flocks.manage' => 'can_manage_flocks',
            'flocks.redistribute' => 'can_redistribute_flocks',
            'flocks.finalize' => 'can_finalize_flocks',
            'management-plans.view' => 'can_view_management_plans',
            'management-plans.manage' => 'can_manage_management_plans',
            'management-plans.execute' => 'can_execute_management_plans',
            'management-plans.stock.manage' => 'can_manage_medicine_stock',
            'breeds.view' => 'can_view_breeds',
            'breeds.manage' => 'can_manage_breeds',
            'mortality.view' => 'can_view_mortality',
            'mortality.manage' => 'can_manage_mortality',
            'egg-collections.view' => 'can_view_egg_collections',
            'egg-collections.manage' => 'can_manage_egg_collections',
            'egg-stock.view' => 'can_view_egg_stock',
            'egg-stock.move' => 'can_move_egg_stock',
            'egg-stock.adjust' => 'can_adjust_egg_stock',
            'weighings.view' => 'can_view_weighings',
            'weighings.manage' => 'can_manage_weighings',
            'weighing-settings.manage' => 'can_manage_weighing_settings',
            'geography.view' => 'can_view_geography',
            'geography.manage' => 'can_manage_geography',
            'production-units.view' => 'can_view_production_units',
            'production-units.manage' => 'can_manage_production_units',
            'poultry-houses.view' => 'can_view_poultry_houses',
            'poultry-houses.manage' => 'can_manage_poultry_houses',
            'suppliers.view' => 'can_view_suppliers',
            'suppliers.manage' => 'can_manage_suppliers',
            'products.view' => 'can_view_products',
            'products.manage' => 'can_manage_products',
            'inventory.view' => 'can_view_inventory',
            'inventory.move' => 'can_move_inventory',
            'inventory.adjust' => 'can_adjust_inventory',
            'inventory.manage' => 'can_manage_inventory',
            'reports.view' => 'can_view_reports',
            'reports.export' => 'can_export_reports',
            'reports.presets.manage' => 'can_manage_report_presets',
            'reports.share' => 'can_share_reports',
            'feed-recipes.view' => 'can_view_feed_recipes',
            'feed-recipes.manage' => 'can_manage_feed_recipes',
            'identity.personal.login' => 'can_login_native',
            'identity.web.login' => 'can_login_web',
            'identity.shared.login' => 'can_login_shared',
            'identity.users.manage' => 'can_manage_users',
            'identity.pins.manage' => 'can_manage_user_pins',
            'identity.shared-devices.manage' => 'can_manage_shared_devices',
            'identity.sessions.manage' => 'can_manage_user_sessions',
            'delivery.start' => 'can_start_deliveries',
            'delivery.fuel.record' => 'can_record_delivery_fuel',
            'delivery.view-own' => 'can_view_own_deliveries',
            'delivery.update-own' => 'can_update_own_deliveries',
            'delivery.location.publish' => 'can_publish_delivery_location',
            'delivery.monitor' => 'can_monitor_deliveries',
            'delivery.history' => 'can_view_delivery_history',
            'vehicles.view' => 'can_view_vehicles',
            'vehicles.manage' => 'can_manage_vehicles',
            'vehicles.use' => 'can_use_vehicles',
            'vehicles.readings.manage' => 'can_manage_vehicle_readings',
            'vehicles.fuel.view' => 'can_view_vehicle_fuel',
            'vehicles.fuel.manage' => 'can_manage_vehicle_fuel',
            'vehicles.maintenance.view' => 'can_view_vehicle_maintenance',
            'vehicles.maintenance.manage' => 'can_manage_vehicle_maintenance',
            'vehicles.documents.view' => 'can_view_vehicle_documents',
            'vehicles.documents.manage' => 'can_manage_vehicle_documents',
        ];
        foreach ($names as $old => $new) {
            DB::table('permissions')->where('name', $old)->where('guard_name', 'web')->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE model_has_permissions DROP CONSTRAINT no_direct_user_permissions');
        Schema::table('model_has_roles', function (Blueprint $table): void {
            $table->dropUnique('model_has_roles_single_role_unique');
        });
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn(['label', 'description', 'version']);
        });
        // Las claves públicas nuevas no se revierten: volver a desplegar requiere coordinar el contrato.
    }
};
