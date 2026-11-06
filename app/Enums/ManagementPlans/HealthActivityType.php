<?php

namespace App\Enums\ManagementPlans;

enum HealthActivityType: string
{
    case Vaccination = 'vaccination';
    case Medication = 'medication';
}
