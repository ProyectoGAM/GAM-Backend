<?php

namespace Database\Seeders\SuppliersAndCatalogs;

use App\Actions\SuppliersAndCatalogs\CreateMedicineAction;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

final class MedicineDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $supplier = Supplier::query()->where('normalized_name', 'agroinsumos del sur')->firstOrFail();
        $action = app(CreateMedicineAction::class);

        foreach ([
            ['key' => '00000000-0000-4000-8000-000000000701', 'name' => 'Medicamento demo A', 'description' => 'Ficha ficticia de catálogo para pruebas locales.'],
            ['key' => '00000000-0000-4000-8000-000000000702', 'name' => 'Medicamento demo B', 'description' => 'Segunda ficha ficticia sin dosis ni asignación a planes.'],
        ] as $medicine) {
            $action->execute([
                'name' => $medicine['name'],
                'description' => $medicine['description'],
                'supplier_id' => $supplier->id,
                'idempotency_key' => $medicine['key'],
            ], $actor, 'seeder');
        }
    }
}
