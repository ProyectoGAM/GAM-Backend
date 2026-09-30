<?php

namespace Database\Seeders\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateVaccineAction;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

final class VaccineDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $product = Product::query()->where('sku', 'VAC-001')->first();
        if ($product !== null && $product->vaccine()->exists()) {
            return;
        }

        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $supplier = Supplier::query()->where('normalized_name', 'agroinsumos del sur')->firstOrFail();
        app(CreateVaccineAction::class)->execute([
            'sku' => 'VAC-001',
            'name' => 'Vacuna aviar Newcastle',
            'description' => 'Ficha ficticia de la vacuna demo ya registrada en inventario.',
            'details' => 'Datos de catálogo para el entorno local.',
            'supplier_id' => $supplier->getKey(),
            'base_unit' => 'dose',
            'idempotency_key' => '00000000-0000-4000-8000-000000000703',
        ], $actor, 'seeder');
    }
}
