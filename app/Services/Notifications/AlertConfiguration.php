<?php

namespace App\Services\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AlertConfiguration
{
    public function query(?int $unitId): Builder
    {
        return DB::table('alert_configurations')->where('production_unit_id', $unitId);
    }

    /** @return array<string, bool|int|string|null> */
    public function defaults(OperationalAlertType $type): array
    {
        return ['habilitada' => true, 'urgencia_fija' => null, ...array_map(
            static fn (array $field): int => $field['predeterminado'],
            $type->thresholds(),
        )];
    }

    /** @return array<string, bool|int|string|null> */
    public function effective(OperationalAlertType $type, ?string $stored): array
    {
        return [...$this->defaults($type), ...($stored === null ? [] : json_decode($stored, true, flags: JSON_THROW_ON_ERROR))];
    }

    /** @return array<string, bool|int|string|null> */
    public function get(OperationalAlertType $type, ?int $unitId): array
    {
        return $this->effective($type, $this->query($unitId)->where('type', $type->value)->value('settings'));
    }

    /** @param array<string, bool|int|string|null> $settings */
    public function urgency(array $settings, NotificationUrgency $calculated): NotificationUrgency
    {
        return $settings['urgencia_fija'] === null ? $calculated : NotificationUrgency::from((string) $settings['urgencia_fija']);
    }
}
