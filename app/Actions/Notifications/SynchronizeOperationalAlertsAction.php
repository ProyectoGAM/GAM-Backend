<?php

namespace App\Actions\Notifications;

use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Events\Notifications\NotificationInboxChanged;
use App\Models\Notifications\AppNotification;
use App\Services\Notifications\AlertConfiguration;
use App\Services\Notifications\AlertRecipients;
use App\Services\Notifications\OperationalAlertProjection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class SynchronizeOperationalAlertsAction
{
    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ResolveAlertNotificationAction $resolve,
        private AlertConfiguration $configuration,
        private OperationalAlertProjection $operationalAlerts,
    ) {}

    /**
     * Sincroniza una evaluación ya serializada por tipo y alcance, sea general o de una unidad productiva.
     *
     * @param  array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}>  $pending
     * @param  array<string, bool|int|string|null>|null  $settings
     */
    public function execute(?int $unitId, OperationalAlertType $type, string $permission, string $entityType, CarbonImmutable $evaluatedAt, array $pending, ?array $settings = null): void
    {
        $settings ??= $this->configuration->get($type, $unitId);
        if (! $settings['habilitada']) {
            $pending = [];
        }
        foreach ($pending as $key => $alert) {
            $pending[$key]['urgency'] = $this->configuration->urgency($settings, $alert['urgency']);
        }
        $this->operationalAlerts->synchronize($unitId, $type, $permission, $entityType, $evaluatedAt, $pending);

        $recipients = $pending === [] ? [] : $this->recipients->ids($unitId, $permission);
        $open = [];
        $notices = AppNotification::query()->where('module', $type->module())->where('type', $type->value)
            ->where('production_unit_id', $unitId)->where('is_alert', true)->whereNull('resolved_at');

        foreach ($notices->lazyById(100) as $notice) {
            $key = Str::beforeLast($notice->source_key, ':');
            $alert = $pending[$key] ?? null;
            if ($alert === null || ! isset($recipients[$notice->recipient_id])) {
                $this->resolve->execute($notice);

                continue;
            }

            $open[$key][$notice->recipient_id] = true;
            if ($alert['urgency']->rank() > $notice->urgency->rank()) {
                $notice->read_at = null;
            }
            $notice->fill($alert);
            if ($notice->isDirty()) {
                $notice->save();
                NotificationInboxChanged::dispatch($notice->recipient_id, $unitId);
            }
        }

        foreach ($pending as $key => $alert) {
            foreach ($recipients as $recipientId => $_) {
                if (isset($open[$key][$recipientId])) {
                    continue;
                }

                $this->publish->execute(new NotificationData(
                    recipientId: $recipientId,
                    type: $type->value,
                    sourceKey: $key.':'.Str::uuid(),
                    title: $alert['title'],
                    message: $alert['message'],
                    occurredAt: $evaluatedAt,
                    productionUnitId: $unitId,
                    entityType: $entityType,
                    entityId: $alert['entity_id'],
                    targetPath: $alert['target_path'],
                    urgency: $alert['urgency'],
                    module: $type->module(),
                    isAlert: true,
                ));
            }
        }
    }
}
