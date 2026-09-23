<?php

namespace Database\Seeders\ManagementPlans;

use App\Actions\ManagementPlans\SavePlanTemplateAction;
use App\Models\ManagementPlans\PlanTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

final class ManagementPlanDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }
        $actor = User::query()->where('email', config('auth.admin.email'))->firstOrFail();
        $action = app(SavePlanTemplateAction::class);
        $template = PlanTemplate::query()->where('name', 'Plan inicial de manejo (demo)')->first();
        if ($template === null) {
            $operation = $action->create([
                'idempotency_key' => '5b0379ad-593e-4aba-a719-747309b68411',
                'name' => 'Plan inicial de manejo (demo)',
                'description' => 'Datos ficticios basados en prácticas y momentos; fechas y referencias configurables.',
                'activities' => $this->activities(),
            ], $actor, 'seeder');
            $template = PlanTemplate::query()->where('public_id', $operation->result['template']['id'])->firstOrFail();
        }
        if ($template->published_version === null && $template->status === 'active') {
            $action->publish($template, [
                'idempotency_key' => '932e0743-e45c-4334-bec2-9eb0d418eaa1',
                'expected_version' => $template->current_version,
            ], $actor, 'seeder');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function activities(): array
    {
        $activities = [];
        foreach ([
            [1, 'Gumboro'], [2, 'Bronquitis'], [3, 'Gumboro'],
            [4, 'Bronquitis variante'], [7, 'Bronquitis variante'],
            [10, 'Viruela'], [13, 'Influenza'],
            [16, 'Triple (Newcastle, Bronquitis y Síndrome Baja Postura)'],
        ] as [$week, $name]) {
            $activities[] = ['type' => 'vaccination', 'title' => $name, 'timing_kind' => 'week', 'start_week' => $week];
        }
        $activities[] = ['type' => 'weighing', 'title' => 'Pesada al llegar', 'timing_kind' => 'day', 'start_day' => 1];
        $activities[] = ['type' => 'weighing', 'title' => 'Pesada a la semana', 'timing_kind' => 'day', 'start_day' => 8];
        $activities[] = [
            'type' => 'weighing', 'title' => 'Pesada cada 15 días hasta semana 16',
            'timing_kind' => 'day_recurrence', 'start_day' => 23, 'end_week' => 16,
            'interval_days' => 15, 'notes' => 'Inicio del intervalo elegido para estos datos demo; editable en plantilla y lote.',
        ];
        $activities[] = [
            'type' => 'manual_practice', 'title' => 'Despique en planta de incubación',
            'timing_kind' => 'unscheduled', 'notes' => 'Hacer en planta de incubación.',
        ];
        $activities[] = [
            'type' => 'manual_practice', 'title' => 'Despique semana 12',
            'timing_kind' => 'week', 'start_week' => 12,
            'conditional' => true, 'condition' => 'Si amerita.',
        ];
        $activities[] = ['type' => 'manual_practice', 'title' => 'Colocación de nidos', 'timing_kind' => 'week', 'start_week' => 17];
        foreach ([
            [1, 5, 'BB'], [6, 9, 'Recría'], [9, 18, 'Desarrollo'],
            [19, 21, 'Pre postura'], [22, 40, 'Fase 1'], [41, null, 'Fase 2'],
        ] as [$start, $end, $name]) {
            $activities[] = [
                'type' => 'ration_change', 'title' => $name, 'timing_kind' => 'week_range',
                'start_week' => $start, 'end_week' => $end,
            ];
        }
        $activities[] = ['type' => 'flock_movement', 'title' => 'Cambio de galpón', 'timing_kind' => 'unscheduled', 'conditional' => true, 'condition' => 'Según corresponda.'];
        $activities[] = ['type' => 'flock_movement', 'title' => 'Fraccionamiento de lote', 'timing_kind' => 'unscheduled', 'conditional' => true, 'condition' => 'Según corresponda.'];
        $activities[] = ['type' => 'medication', 'title' => 'Tratamientos específicos', 'timing_kind' => 'unscheduled', 'conditional' => true, 'condition' => 'Según corresponda.'];

        return $activities;
    }
}
