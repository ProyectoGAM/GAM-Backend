<?php

namespace Database\Seeders;

use App\Models\Geography\Department;
use Illuminate\Database\Seeder;

final class GeographySeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            'Artigas',
            'Canelones',
            'Cerro Largo',
            'Colonia',
            'Durazno',
            'Flores',
            'Florida',
            'Lavalleja',
            'Maldonado',
            'Montevideo',
            'Paysandú',
            'Río Negro',
            'Rivera',
            'Rocha',
            'Salto',
            'San José',
            'Soriano',
            'Tacuarembó',
            'Treinta y Tres',
        ];

        foreach ($departments as $name) {
            Department::query()->firstOrCreate(['name' => $name]);
        }
    }
}
