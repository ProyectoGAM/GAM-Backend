<?php

namespace App\Enums\Notifications;

use App\Models\Vehicles\VehicleDocument;
use App\Services\Vehicles\VehicleMaintenanceSchedule;

enum OperationalAlertType: string
{
    case DailyEggCollectionMissing = 'daily_egg_collection_missing';
    case ManualFeedingReviewDue = 'manual_feeding_review_due';
    case VaccinationDue = 'vaccination_due';
    case MedicationDue = 'medication_due';
    case BeakTrimmingDue = 'beak_trimming_due';
    case ManualPracticeDue = 'manual_practice_due';
    case FlockMovementDue = 'flock_movement_due';
    case EggCollectionDue = 'egg_collection_due';
    case MortalityDue = 'mortality_due';
    case FeedCoverage = 'feed_coverage';
    case PoultryHouseOverCapacity = 'poultry_house_over_capacity';
    case StockMinimumReached = 'stock_minimum_reached';
    case EggStockNegative = 'egg_stock_negative';
    case WeighingAnomaly = 'weighing_anomaly';
    case DeliveryEggSurplus = 'delivery_egg_surplus';
    case DeliveryEggBalanceNegative = 'delivery_egg_balance_negative';
    case VehicleMaintenanceDue = 'vehicle_maintenance_due';
    case VehicleMaintenancePlanDue = 'vehicle_maintenance_plan_due';
    case VehicleDocumentExpiring = 'vehicle_document_expiring';

    public function appliesTo(?int $unitId): bool
    {
        return match ($this) {
            self::StockMinimumReached, self::DeliveryEggSurplus, self::DeliveryEggBalanceNegative => true,
            self::VehicleMaintenanceDue, self::VehicleMaintenancePlanDue, self::VehicleDocumentExpiring => $unitId === null,
            default => $unitId !== null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DailyEggCollectionMissing => 'Falta de recolección diaria de huevos',
            self::ManualFeedingReviewDue => 'Revisión de ración manual pendiente',
            self::VaccinationDue => 'Vacunación programada',
            self::MedicationDue => 'Medicación programada',
            self::BeakTrimmingDue => 'Despique programado',
            self::ManualPracticeDue => 'Práctica manual obligatoria',
            self::FlockMovementDue => 'Movimiento obligatorio de lote',
            self::EggCollectionDue => 'Recolección obligatoria del plan',
            self::MortalityDue => 'Registro obligatorio de mortalidad',
            self::FeedCoverage => 'Cobertura de ración',
            self::PoultryHouseOverCapacity => 'Galpón por encima de su capacidad',
            self::StockMinimumReached => 'Stock en el mínimo o por debajo',
            self::EggStockNegative => 'Stock de huevos en negativo',
            self::WeighingAnomaly => 'Pesaje fuera del rango esperado',
            self::DeliveryEggSurplus => 'Huevos sobrantes en un reparto',
            self::DeliveryEggBalanceNegative => 'Saldo de huevos del reparto en negativo',
            self::VehicleMaintenanceDue => 'Intervención de vehículo prevista',
            self::VehicleMaintenancePlanDue => 'Plan preventivo de vehículo',
            self::VehicleDocumentExpiring => 'Vencimiento de documento de vehículo',
        };
    }

    public function module(): string
    {
        return match ($this) {
            self::DailyEggCollectionMissing, self::WeighingAnomaly => 'lots',
            self::PoultryHouseOverCapacity => 'farm_structure',
            self::StockMinimumReached, self::EggStockNegative => 'inventory',
            self::DeliveryEggSurplus, self::DeliveryEggBalanceNegative => 'deliveries',
            self::VehicleMaintenanceDue, self::VehicleMaintenancePlanDue, self::VehicleDocumentExpiring => 'vehicles',
            default => 'management_plans',
        };
    }

    /** @return array<string, array{etiqueta: string, predeterminado: int, minimo: int, maximo: int}> */
    public function thresholds(): array
    {
        return match ($this) {
            self::PoultryHouseOverCapacity => [
                'dias_anticipacion' => ['etiqueta' => 'Días de aviso antes de la adultez', 'predeterminado' => 7, 'minimo' => 0, 'maximo' => 365],
                'exceso_minimo_aves' => ['etiqueta' => 'Exceso mínimo de aves', 'predeterminado' => 1, 'minimo' => 1, 'maximo' => 2147483647],
            ],
            self::EggStockNegative, self::DeliveryEggBalanceNegative => [
                'deficit_minimo_huevos' => ['etiqueta' => 'Déficit mínimo de huevos', 'predeterminado' => 1, 'minimo' => 1, 'maximo' => 2147483647],
            ],
            self::DeliveryEggSurplus => [
                'sobrante_minimo_huevos' => ['etiqueta' => 'Sobrante mínimo de huevos devueltos', 'predeterminado' => 1, 'minimo' => 1, 'maximo' => 2147483647],
            ],
            self::VehicleMaintenanceDue => [
                'dias_anticipacion' => ['etiqueta' => 'Días de aviso antes de la intervención', 'predeterminado' => 3, 'minimo' => 0, 'maximo' => 365],
            ],
            self::VehicleMaintenancePlanDue => [
                'dias_anticipacion' => ['etiqueta' => 'Días de aviso antes del vencimiento', 'predeterminado' => VehicleMaintenanceSchedule::NOTICE_DAYS, 'minimo' => 0, 'maximo' => 365],
                'kilometros_anticipacion' => ['etiqueta' => 'Kilómetros de aviso antes del límite', 'predeterminado' => VehicleMaintenanceSchedule::NOTICE_KILOMETERS, 'minimo' => 0, 'maximo' => 1000000],
            ],
            self::VehicleDocumentExpiring => [
                'dias_anticipacion' => ['etiqueta' => 'Días de aviso antes del vencimiento', 'predeterminado' => VehicleDocument::EXPIRY_NOTICE_DAYS, 'minimo' => 0, 'maximo' => 365],
            ],
            default => [],
        };
    }
}
