<?php

namespace App\Services\Deliveries;

use App\Actions\Notifications\PublishNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Deliveries\Delivery;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Notifications\AlertConfiguration;
use App\Services\Notifications\AlertRecipients;

final readonly class DeliveryEggAlerts
{
    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ProductionUnitAccess $access,
        private AlertConfiguration $configuration,
    ) {}

    public function surplus(Delivery $delivery, int $unitId, int $returned): void
    {
        $type = OperationalAlertType::DeliveryEggSurplus;
        $recipientScope = $this->recipientScope($delivery, $unitId);
        $settings = $this->configuration->get($type, $recipientScope);
        if (! $settings['habilitada'] || $returned < (int) $settings['sobrante_minimo_huevos']) {
            return;
        }

        $this->notify(
            $delivery,
            $unitId,
            $recipientScope,
            $type,
            $this->configuration->urgency($settings, NotificationUrgency::Low),
            'Huevos sobrantes en un reparto',
            "Al cerrar el reparto se devolvieron {$returned} huevos a stock.",
        );
    }

    public function negativeDeliveryBalance(Delivery $delivery, int $balance): void
    {
        if ($balance >= 0) {
            return;
        }

        $type = OperationalAlertType::DeliveryEggBalanceNegative;
        $unitId = $delivery->production_unit_id;
        $recipientScope = $this->recipientScope($delivery, $unitId);
        $settings = $this->configuration->get($type, $recipientScope);
        if (! $settings['habilitada'] || $balance > -(int) $settings['deficit_minimo_huevos']) {
            return;
        }

        $this->notify(
            $delivery,
            $unitId,
            $recipientScope,
            $type,
            $this->configuration->urgency($settings, NotificationUrgency::High),
            'Saldo de huevos del reparto en negativo',
            "El saldo del reparto quedó en {$balance} huevos al comparar lo cargado con lo entregado y devuelto.",
        );
    }

    private function recipientScope(Delivery $delivery, ?int $unitId): ?int
    {
        // Los repartos con datos de varias UP usan el alcance general para configurar y notificar.
        return $unitId !== null && $this->access->within($unitId, fn (): bool => Delivery::query()->whereKey($delivery->id)->exists())
            ? $unitId
            : null;
    }

    private function notify(Delivery $delivery, ?int $unitId, ?int $recipientScope, OperationalAlertType $type, NotificationUrgency $urgency, string $title, string $message): void
    {
        $recipients = $this->recipients->ids($recipientScope, 'can_view_delivery_history')
            + $this->recipients->ids($recipientScope, 'can_monitor_deliveries');

        foreach ($recipients as $recipientId => $_) {
            $this->publish->execute(new NotificationData(
                recipientId: $recipientId,
                type: $type->value,
                sourceKey: $delivery->public_id,
                title: $title,
                message: $message,
                occurredAt: now(),
                productionUnitId: $unitId,
                entityType: 'delivery',
                entityId: $delivery->public_id,
                targetPath: '/administracion/repartos/repartos',
                urgency: $urgency,
                module: $type->module(),
                isAlert: true,
            ));
        }
    }
}
