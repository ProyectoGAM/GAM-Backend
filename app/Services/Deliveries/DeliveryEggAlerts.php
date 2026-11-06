<?php

namespace App\Services\Deliveries;

use App\Actions\Notifications\PublishNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Models\Deliveries\Delivery;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Notifications\AlertRecipients;

final readonly class DeliveryEggAlerts
{
    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ProductionUnitAccess $access,
    ) {}

    public function surplus(Delivery $delivery, int $unitId, int $returned): void
    {
        $this->notify(
            $delivery,
            $unitId,
            'delivery_egg_surplus',
            $delivery->public_id,
            NotificationUrgency::Low,
            'Huevos sobrantes en un reparto',
            "Al cerrar el reparto se devolvieron {$returned} huevos a stock.",
        );
    }

    public function negativeDeliveryBalance(Delivery $delivery, int $balance): void
    {
        if ($balance >= 0) {
            return;
        }

        $this->notify(
            $delivery,
            $delivery->production_unit_id,
            'delivery_egg_balance_negative',
            $delivery->public_id,
            NotificationUrgency::High,
            'Saldo de huevos del reparto en negativo',
            "El saldo del reparto quedó en {$balance} huevos al comparar lo cargado con lo entregado y devuelto.",
        );
    }

    private function notify(Delivery $delivery, ?int $unitId, string $type, string $sourceKey, NotificationUrgency $urgency, string $title, string $message): void
    {
        // Los repartos con datos de varias UP sólo se notifican a cuentas de alcance general.
        $visibleInUnit = $unitId !== null && $this->access->within($unitId, fn (): bool => Delivery::query()->whereKey($delivery->id)->exists());
        $recipientScope = $visibleInUnit ? $unitId : null;
        $recipients = $this->recipients->ids($recipientScope, 'can_view_delivery_history')
            + $this->recipients->ids($recipientScope, 'can_monitor_deliveries');

        foreach ($recipients as $recipientId => $_) {
            $this->publish->execute(new NotificationData(
                recipientId: $recipientId,
                type: $type,
                sourceKey: $sourceKey,
                title: $title,
                message: $message,
                occurredAt: now(),
                productionUnitId: $unitId,
                entityType: 'delivery',
                entityId: $delivery->public_id,
                targetPath: '/administracion/repartos/repartos',
                urgency: $urgency,
                module: 'deliveries',
                isAlert: true,
            ));
        }
    }
}
