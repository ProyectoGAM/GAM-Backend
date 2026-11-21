<?php

namespace Database\Seeders\ReportingAndAnalytics;

use App\Actions\ReportingAndAnalytics\CreateReportPresetAction;
use App\Models\ReportingAndAnalytics\ReportPreset;
use App\Models\User;
use App\Services\ReportingAndAnalytics\ReportSourceRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DefaultReportPresetsSeeder extends Seeder
{
    /** Crea configuraciones privadas sin generar operaciones demo ni sobrescribir personalizaciones. */
    public function run(CreateReportPresetAction $create, ReportSourceRegistry $registry): void
    {
        DB::transaction(function () use ($create, $registry): void {
            $actor = User::query()->where('email', config('auth.admin.email'))->lockForUpdate()->firstOrFail();
            foreach (['Manejos' => 'management.overview', 'Repartos' => 'deliveries.collections', 'Postura' => 'lots.egg-production'] as $name => $key) {
                if (ReportPreset::query()->where('user_id', $actor->id)->where('normalized_name', Str::lower($name))->exists()) {
                    continue;
                }
                $columns = $registry->get($key)->definition()->defaultColumns;
                $create->execute(['name' => $name, 'source_key' => $key,
                    'configuration' => ['columns' => $columns, 'date_preset' => 'last_month']], $actor);
            }
        });
    }
}
