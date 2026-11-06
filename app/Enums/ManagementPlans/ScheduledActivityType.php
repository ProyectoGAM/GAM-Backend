<?php

namespace App\Enums\ManagementPlans;

enum ScheduledActivityType: string
{
    case Vaccination = 'vaccination';
    case Medication = 'medication';
    case BeakTrimming = 'beak_trimming';
    case ManualPractice = 'manual_practice';
    case FlockMovement = 'flock_movement';
    case EggCollection = 'egg_collection';
    case Mortality = 'mortality';

    public function planType(): string
    {
        return $this === self::BeakTrimming ? self::ManualPractice->value : $this->value;
    }

    public function warningDays(): int
    {
        return match ($this) {
            self::Vaccination, self::Medication => 7,
            self::BeakTrimming => 3,
            default => 0,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Vaccination => 'Vacunación pendiente',
            self::Medication => 'Medicación pendiente',
            self::BeakTrimming => 'Despique pendiente',
            default => 'Tarea de manejo pendiente',
        };
    }
}
