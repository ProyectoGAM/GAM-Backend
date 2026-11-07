<?php

namespace App\Services\Lots;

use App\Actions\Notifications\PublishNotificationAction;
use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Lots\DailyWeighingEntry;
use App\Models\Lots\Weighing;
use App\Models\Notifications\AppNotification;
use App\Services\Notifications\AlertConfiguration;
use App\Services\Notifications\AlertRecipients;

final readonly class WeighingAnomalyAlerts
{
    private const OperationalAlertType TYPE = OperationalAlertType::WeighingAnomaly;

    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ResolveAlertNotificationAction $resolve,
        private AlertConfiguration $configuration,
    ) {}

    public function publish(DailyWeighingEntry|Weighing $measurement, string $operationId): void
    {
        if ($measurement instanceof DailyWeighingEntry) {
            $daily = $measurement->dailyWeighing;
            $flock = $daily->flock;
            $unitId = $flock->production_unit_id;
            $detail = '/'.$daily->public_id;
        } else {
            $flock = $measurement->flock;
            $unitId = $measurement->production_unit_id;
            $detail = '';
        }

        $settings = $this->configuration->get(self::TYPE, $unitId);
        if (! $settings['habilitada']) {
            return;
        }

        foreach ($this->recipients->ids($unitId, 'can_view_weighings') as $recipientId => $_) {
            $this->publish->execute(new NotificationData(
                recipientId: $recipientId,
                type: self::TYPE->value,
                sourceKey: $measurement->public_id.':'.$operationId,
                title: 'Pesaje anómalo pendiente de revisión',
                message: "Revisá el dato de peso fuera del rango esperado en el lote «{$flock->code}».",
                occurredAt: now(),
                productionUnitId: $unitId,
                entityType: $this->entityType($measurement),
                entityId: $measurement->public_id,
                targetPath: '/administracion/lotes/lotes/'.$flock->public_id.'/pesajes'.$detail,
                urgency: $this->configuration->urgency($settings, NotificationUrgency::High),
                module: self::TYPE->module(),
                isAlert: true,
            ));
        }
    }

    public function resolve(DailyWeighingEntry|Weighing $measurement, int $unitId): void
    {
        $alerts = AppNotification::query()->where('module', self::TYPE->module())->where('type', self::TYPE->value)
            ->where('production_unit_id', $unitId)
            ->where('entity_type', $this->entityType($measurement))->where('entity_id', $measurement->public_id)
            ->where('is_alert', true)->whereNull('resolved_at');

        foreach ($alerts->lazyById(100) as $alert) {
            $this->resolve->execute($alert);
        }
    }

    private function entityType(DailyWeighingEntry|Weighing $measurement): string
    {
        return $measurement instanceof DailyWeighingEntry ? 'daily_weighing_entry' : 'weighing';
    }
}
