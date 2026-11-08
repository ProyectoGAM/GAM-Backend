<?php

namespace App\Services\Deliveries;

use App\Actions\Notifications\PublishNotificationAction;
use App\Actions\Notifications\ResolveAlertNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Models\Deliveries\Delivery;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Notifications\AppNotification;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use App\Services\Notifications\AlertConfiguration;
use App\Services\Notifications\AlertRecipients;
use App\Services\Notifications\OperationalAlertProjection;
use Illuminate\Database\Eloquent\Builder;

final readonly class DeliveryEggAlerts
{
    private const OperationalAlertType NEGATIVE_BALANCE = OperationalAlertType::DeliveryEggBalanceNegative;

    private const string TARGET_PATH = '/administracion/repartos/repartos';

    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ProductionUnitAccess $access,
        private AlertConfiguration $configuration,
        private OperationalAlertProjection $operationalAlerts,
        private ResolveAlertNotificationAction $resolveNotification,
    ) {}

    public function surplus(Delivery $delivery, ProductionUnit $unit, int $returned, ?string $sourceKey = null): void
    {
        $type = OperationalAlertType::DeliveryEggSurplus;
        $recipientScope = $this->recipientScope($delivery, $unit->id);
        $settings = $this->configuration->get($type, $recipientScope);
        if (! $settings['habilitada'] || $returned < (int) $settings['sobrante_minimo_huevos']) {
            return;
        }

        $this->notify(
            $delivery,
            $unit->id,
            $recipientScope,
            $type,
            $this->configuration->urgency($settings, NotificationUrgency::Low),
            'Huevos sobrantes en un reparto',
            "Se ingresaron {$returned} huevos sobrantes del reparto en la unidad productiva «{$unit->name}».",
            isAlert: false,
            sourceKey: $sourceKey,
        );
    }

    public function negativeDeliveryBalance(Delivery $delivery, int $balance): void
    {
        $time = now()->toImmutable();
        $originalUnit = $delivery->production_unit_id;
        $unitId = $this->recipientScope($delivery, $originalUnit);
        if ($balance >= 0) {
            $this->operationalAlerts->resolve(null, self::NEGATIVE_BALANCE, $delivery->public_id, $time);
            if ($originalUnit !== null) {
                $this->operationalAlerts->resolve($originalUnit, self::NEGATIVE_BALANCE, $delivery->public_id, $time);
            }
            $this->resolveNotices($this->negativeNotices($delivery));

            return;
        }

        if ($unitId === null && $originalUnit !== null) {
            $this->operationalAlerts->resolve($originalUnit, self::NEGATIVE_BALANCE, $delivery->public_id, $time);
            $this->resolveNotices($this->negativeNotices($delivery)->where('production_unit_id', $originalUnit));
        }

        $settings = $this->configuration->get(self::NEGATIVE_BALANCE, $unitId);
        if (! $settings['habilitada'] || $balance > -(int) $settings['deficit_minimo_huevos']) {
            return;
        }

        $urgency = $this->configuration->urgency($settings, NotificationUrgency::High);
        $title = 'Saldo de huevos del reparto en negativo';
        $episode = $this->operationalAlerts->open($unitId, self::NEGATIVE_BALANCE, 'can_view_delivery_history', 'delivery', $delivery->public_id, $time, [
            'entity_id' => $delivery->public_id, 'target_path' => self::TARGET_PATH, 'urgency' => $urgency, 'title' => $title,
        ]);
        $sourceKey = $this->negativeNotices($delivery)->where('source_key', $delivery->public_id)->exists()
            ? $delivery->public_id : $episode->public_id;
        $this->notify(
            $delivery,
            $unitId,
            $unitId,
            self::NEGATIVE_BALANCE,
            $urgency,
            $title,
            "El saldo del reparto quedó en {$balance} huevos al comparar lo cargado con lo entregado y devuelto.",
            sourceKey: $sourceKey,
        );
    }

    /** @return Builder<AppNotification> */
    private function negativeNotices(Delivery $delivery): Builder
    {
        return AppNotification::query()->where('type', self::NEGATIVE_BALANCE)->where('entity_type', 'delivery')
            ->where('entity_id', $delivery->public_id)->where('is_alert', true)->whereNull('resolved_at');
    }

    /** @param Builder<AppNotification> $notices */
    private function resolveNotices(Builder $notices): void
    {
        foreach ($notices->lazyById(100) as $notice) {
            $this->resolveNotification->execute($notice);
        }
    }

    private function recipientScope(Delivery $delivery, ?int $unitId): ?int
    {
        // Los repartos con datos de varias UP usan el alcance general para configurar y notificar.
        return $unitId !== null && $this->access->within($unitId, fn (): bool => Delivery::query()->whereKey($delivery->id)->exists())
            ? $unitId
            : null;
    }

    private function notify(Delivery $delivery, ?int $unitId, ?int $recipientScope, OperationalAlertType $type, NotificationUrgency $urgency, string $title, string $message, bool $isAlert = true, ?string $sourceKey = null): void
    {
        $recipients = $this->recipients->ids($recipientScope, 'can_view_delivery_history')
            + $this->recipients->ids($recipientScope, 'can_monitor_deliveries');

        foreach ($recipients as $recipientId => $_) {
            $this->publish->execute(new NotificationData(
                recipientId: $recipientId,
                type: $type->value,
                sourceKey: $sourceKey ?? $delivery->public_id,
                title: $title,
                message: $message,
                occurredAt: now(),
                productionUnitId: $unitId,
                entityType: 'delivery',
                entityId: $delivery->public_id,
                targetPath: self::TARGET_PATH,
                urgency: $urgency,
                module: $type->module(),
                isAlert: $isAlert,
            ));
        }
    }
}
