<?php

namespace App\Services\Inventory;

use App\Actions\Notifications\PublishNotificationAction;
use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Notifications\AppNotification;
use App\Services\Notifications\AlertConfiguration;
use App\Services\Notifications\AlertRecipients;
use App\Services\Notifications\OperationalAlertProjection;

final readonly class FeedManufactureAlerts
{
    private const OperationalAlertType TYPE = OperationalAlertType::FeedManufactureWithShortage;

    public function __construct(
        private AlertConfiguration $configuration,
        private OperationalAlertProjection $operationalAlerts,
        private AlertRecipients $recipients,
        private PublishNotificationAction $publishNotification,
        private ResolveAlertNotificationAction $resolveNotification,
    ) {}

    public function publish(FeedStockOperation $operation): void
    {
        $unitId = $operation->production_unit_id;
        $settings = $this->configuration->get(self::TYPE, $unitId);
        if (! $settings['habilitada']) {
            return;
        }

        $entityId = (string) $operation->getKey();
        $title = self::TYPE->label();
        $path = '/administracion/inventario/existencias/raciones';
        $urgency = $this->configuration->urgency($settings, NotificationUrgency::High);
        $this->operationalAlerts->open($unitId, self::TYPE, 'can_view_feed_stock', 'feed_stock_operation', $operation->operation_id, $operation->occurred_at, [
            'entity_id' => $entityId, 'target_path' => $path, 'urgency' => $urgency, 'title' => $title,
        ]);

        foreach ($this->recipients->ids($unitId, 'can_view_feed_stock') as $recipientId => $_) {
            $this->publishNotification->execute(new NotificationData(
                recipientId: $recipientId,
                type: self::TYPE->value,
                sourceKey: $operation->operation_id,
                title: $title,
                message: "La elaboración #{$entityId} consumió ingredientes con stock insuficiente.",
                occurredAt: $operation->occurred_at,
                productionUnitId: $unitId,
                entityType: 'feed_stock_operation',
                entityId: $entityId,
                targetPath: $path,
                urgency: $urgency,
                module: self::TYPE->module(),
                isAlert: true,
            ));
        }
    }

    public function resolve(FeedStockOperation $operation): void
    {
        $this->operationalAlerts->resolve($operation->production_unit_id, self::TYPE, $operation->operation_id, now()->toImmutable());
        $notifications = AppNotification::query()->where('production_unit_id', $operation->production_unit_id)
            ->where('type', self::TYPE->value)->where('source_key', $operation->operation_id)
            ->where('is_alert', true)->whereNull('resolved_at');

        foreach ($notifications->lazyById(100) as $notification) {
            $this->resolveNotification->execute($notification);
        }
    }
}
