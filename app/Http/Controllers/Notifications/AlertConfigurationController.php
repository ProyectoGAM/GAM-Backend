<?php

namespace App\Http\Controllers\Notifications;

use App\Actions\Notifications\UpdateAlertConfigurationAction;
use App\Enums\Notifications\AlertTimingType;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Http\Requests\Notifications\ShowAlertConfigurationRequest;
use App\Http\Requests\Notifications\UpdateAlertConfigurationRequest;
use App\Models\FarmStructure\ProductionUnit;
use App\Services\Notifications\AlertConfiguration;
use Illuminate\Http\JsonResponse;

final readonly class AlertConfigurationController
{
    public function __construct(private AlertConfiguration $configuration) {}

    public function index(ShowAlertConfigurationRequest $request, ?ProductionUnit $productionUnit = null): JsonResponse
    {
        $unitId = $productionUnit?->id;
        $stored = $this->configuration->query($unitId)->pluck('settings', 'type');
        $items = [];
        foreach (OperationalAlertType::cases() as $type) {
            if ($type->appliesTo($unitId)) {
                $items[] = $this->describe($type, $productionUnit, $this->configuration->effective($type, $stored->get($type->value)));
            }
        }

        return response()->json(['data' => $items]);
    }

    public function update(
        UpdateAlertConfigurationRequest $request,
        OperationalAlertType $operationalAlertType,
        UpdateAlertConfigurationAction $action,
    ): JsonResponse {
        return $this->save($request, $operationalAlertType, $action, null);
    }

    public function updateUnit(
        UpdateAlertConfigurationRequest $request,
        ProductionUnit $productionUnit,
        OperationalAlertType $operationalAlertType,
        UpdateAlertConfigurationAction $action,
    ): JsonResponse {
        return $this->save($request, $operationalAlertType, $action, $productionUnit);
    }

    private function save(
        UpdateAlertConfigurationRequest $request,
        OperationalAlertType $operationalAlertType,
        UpdateAlertConfigurationAction $action,
        ?ProductionUnit $productionUnit,
    ): JsonResponse {
        $settings = $action->execute($operationalAlertType, $productionUnit, $request->changes(), $request->actor());

        return response()->json(['data' => $this->describe($operationalAlertType, $productionUnit, $settings)]);
    }

    /** @param array<string, bool|int|string|null> $settings @return array<string, mixed> */
    private function describe(OperationalAlertType $type, ?ProductionUnit $unit, array $settings): array
    {
        $fields = [
            ['clave' => 'habilitada', 'etiqueta' => 'Habilitada', 'tipo' => 'booleano', 'predeterminado' => true],
            [
                'clave' => 'urgencia_fija', 'etiqueta' => 'Urgencia fija', 'tipo' => 'seleccion',
                'predeterminado' => null, 'opciones' => [null, ...array_column(NotificationUrgency::cases(), 'value')],
            ],
        ];
        foreach ($type->thresholds() as $key => $field) {
            $fields[] = ['clave' => $key, 'tipo' => 'entero', ...$field];
        }

        return [
            'tipo' => $type->value,
            'nombre' => $type->label(),
            'modulo' => $type->module(),
            'unidad_productiva_id' => $unit?->id,
            'ruta' => $unit === null
                ? route('api.v1.notifications.configuration.update', [$type->value], absolute: false)
                : route('api.v1.notifications.unit-configuration.update', [$unit, $type->value], absolute: false),
            'configuracion' => $settings,
            'campos' => $fields,
            'criterio_relacionado' => $this->relatedCriteria($type, $unit),
        ];
    }

    /** @return array<string, mixed>|null */
    private function relatedCriteria(OperationalAlertType $type, ?ProductionUnit $unit): ?array
    {
        $timing = AlertTimingType::tryFrom($type->value);
        if ($timing !== null && $unit !== null) {
            return [
                'nombre' => 'Umbrales de tiempo por nivel',
                'ruta' => route('api.v1.notifications.thresholds.update', [$unit, $timing->value], absolute: false),
                'metodo' => 'PUT',
                'valores' => $timing->thresholds($unit)->toArray(),
            ];
        }

        return match ($type) {
            OperationalAlertType::FeedCoverage => $unit === null ? null : [
                'nombre' => 'Días de cobertura por nivel',
                'ruta' => route('api.v1.feed-recipes.coverage-alerts.update', [$unit], absolute: false),
                'metodo' => 'PUT',
                'valores' => [
                    'dias_aviso_bajo' => $unit->feed_coverage_low_days,
                    'dias_aviso' => $unit->feed_coverage_warning_days,
                    'dias_urgencia' => $unit->feed_coverage_urgent_days,
                ],
            ],
            OperationalAlertType::WeighingAnomaly, OperationalAlertType::PoultryHouseOverCapacity => [
                'nombre' => 'Configuración general de pesajes',
                'ruta' => route('api.v1.weighings.settings.update', absolute: false),
                'metodo' => 'PUT',
                'valores' => null,
            ],
            OperationalAlertType::StockMinimumReached => [
                'nombre' => 'Stock mínimo por producto y ubicación',
                'ruta' => str_replace('STOCK_BALANCE', '{stockBalance}', route('api.v1.inventory.balances.minimum', ['stockBalance' => 'STOCK_BALANCE'], absolute: false)),
                'metodo' => 'PATCH',
                'valores' => null,
            ],
            default => null,
        };
    }
}
