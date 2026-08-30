<?php

namespace Database\Factories\ReportingAndAnalytics;

use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportPreset>
 */
final class ReportPresetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'normalized_name' => fake()->unique()->slug(3),
            'source_key' => 'inventario.saldos-stock',
            'definition_version' => '1.0',
            'configuration' => [
                'columnas' => ['producto', 'unidad_base', 'cantidad_disponible'],
                'filtros' => [],
                'desde' => null,
                'hasta' => null,
                'ordenamientos' => [['campo' => 'producto', 'direccion' => 'asc']],
                'agrupaciones' => [],
                'metricas' => [],
                'pagina' => 1,
                'por_pagina' => 50,
            ],
        ];
    }
}
