<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_stops', function (Blueprint $table): void {
            // Las entregas anteriores no identificaban presentaciones; NULL conserva esa incertidumbre.
            $table->json('items')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('delivery_stops')->whereNotNull('items')->exists()) {
            throw new RuntimeException('No se puede revertir sin perder el detalle de presentaciones entregadas.');
        }

        Schema::table('delivery_stops', function (Blueprint $table): void {
            $table->dropColumn('items');
        });
    }
};
