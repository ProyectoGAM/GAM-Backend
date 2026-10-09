<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_loads', function (Blueprint $table): void {
            $table->foreignId('production_unit_id')->nullable()->constrained('production_units')->restrictOnDelete();
        });
        // El flujo anterior descontaba cada carga de la UP del reparto: ese origen es conocido.
        DB::statement('UPDATE delivery_loads SET production_unit_id = deliveries.production_unit_id FROM deliveries WHERE delivery_loads.delivery_id = deliveries.id');
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->foreignId('return_production_unit_id')->nullable()->constrained('production_units')->restrictOnDelete();
            $table->json('returned_items')->nullable();
        });
        // Los cierres anteriores conocen el destino, pero no permiten reconstruir el embalaje.
        DB::statement('UPDATE deliveries SET return_production_unit_id = production_unit_id WHERE closed_at IS NOT NULL AND returned_quantity > 0');
        Schema::table('delivery_stops', function (Blueprint $table): void {
            $table->decimal('total_amount', 22, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_stops', fn (Blueprint $table) => $table->dropColumn('total_amount'));
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_production_unit_id');
            $table->dropColumn('returned_items');
        });
        Schema::table('delivery_loads', fn (Blueprint $table) => $table->dropConstrainedForeignId('production_unit_id'));
    }
};
