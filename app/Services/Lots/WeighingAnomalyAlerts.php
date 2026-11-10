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
use App\Services\Notifications\OperationalAlertProjection;

final readonly class WeighingAnomalyAlerts
{
    private const OperationalAlertType TYPE = OperationalAlertType::WeighingAnomaly;

    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ResolveAlertNotificationAction $resolve,
        private AlertConfiguration $configuration,
        private OperationalAlertProjection $operationalAlerts,
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

        $entityType = $this->entityType($measurement);
        $targetPath = '/administracion/lotes/lotes/'.$flock->public_id.'/pesajes'.$detail;
        $title = 'Pesaje anómalo pendiente de revisión';
        $urgency = $this->configuration->urgency($settings, NotificationUrgency::High);
        $occurredAt = now()->toImmutable();
        $this->operationalAlerts->open($unitId, self::TYPE, 'can_view_weighings', $entityType, $entityType.':'.$measurement->public_id, $occurredAt, [
            'entity_id' => $measurement->public_id, 'target_path' => $targetPath, 'title' => $title, 'urgency' => $urgency,
        ]);

        foreach ($this->recipients->ids($unitId, 'can_view_weighings') as $recipientId => $_) {
            $this->publish->execute(new NotificationData(
                recipientId: $recipientId,
                type: self::TYPE->value,
                sourceKey: $measurement->public_id.':'.$operationId,
                title: $title,
                message: "Revisá el dato de peso fuera del rango esperado en el lote «{$flock->code}».",
                occurredAt: $occurredAt,
                productionUnitId: $unitId,
                entityType: $entityType,
                entityId: $measurement->public_id,
                targetPath: $targetPath,
                urgency: $urgency,
                module: self::TYPE->module(),
                isAlert: true,
            ));
        }
    }

    public function resolve(DailyWeighingEntry|Weighing $measurement, int $unitId): void
    {
        $entityType = $this->entityType($measurement);
        $this->operationalAlerts->resolve($unitId, self::TYPE, $entityType.':'.$measurement->public_id, now()->toImmutable());
        $alerts = AppNotification::query()->where('module', self::TYPE->module())->where('type', self::TYPE->value)
            ->where('production_unit_id', $unitId)
            ->where('entity_type', $entityType)->where('entity_id', $measurement->public_id)
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
