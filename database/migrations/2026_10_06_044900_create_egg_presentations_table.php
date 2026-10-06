<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('egg_presentations', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->string('category', 40)->default('custom');
            $table->unsignedInteger('eggs_per_unit');
            $table->unsignedInteger('default_unit_price')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE egg_presentations ADD CONSTRAINT egg_presentations_values_check CHECK (eggs_per_unit > 0 AND (default_unit_price IS NULL OR default_unit_price >= 0))');

        // Conserva las definiciones existentes; sus precios reales debe indicarlos administración.
        foreach ([
            ['huevo', 'Huevos', 'huevos', 1],
            ['maple', 'Maples (30 huevos)', 'maples', 30],
            ['cajon_6', 'Cajones de 6 maples', 'cajones', 180],
            ['cajon_12', 'Cajones de 12 maples', 'cajones', 360],
            ['caja', 'Cajas (12 maples; provisional)', 'cajas', 360],
        ] as [$code, $name, $category, $eggs]) {
            DB::table('egg_presentations')->insert([
                'code' => $code, 'name' => $name, 'category' => $category,
                'eggs_per_unit' => $eggs, 'default_unit_price' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('egg_presentations');
    }
};
