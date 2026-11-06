<?php

namespace App\Enums\Notifications;

use App\Models\FarmStructure\ProductionUnit;
use App\ValueObjects\Notifications\AlertTimingThresholds;

enum AlertTimingType: string
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

    public function minimumDays(): int
    {
        return match ($this) {
            self::DailyEggCollectionMissing, self::ManualFeedingReviewDue => 1,
            default => -365,
        };
    }

    public function thresholds(ProductionUnit $unit): AlertTimingThresholds
    {
        $stored = $unit->alert_timing_thresholds[$this->value] ?? null;
        if ($stored !== null) {
            return new AlertTimingThresholds($stored['bajo'], $stored['medio'], $stored['alto']);
        }

        return match ($this) {
            self::DailyEggCollectionMissing => new AlertTimingThresholds(1, 2, 7),
            self::ManualFeedingReviewDue => new AlertTimingThresholds(null, 21, 29),
            self::VaccinationDue, self::MedicationDue => new AlertTimingThresholds(null, -7, 0),
            self::BeakTrimmingDue => new AlertTimingThresholds(null, -3, 0),
            default => new AlertTimingThresholds(null, null, 0),
        };
    }
}
