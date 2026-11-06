<?php

namespace App\Actions\Notifications;

use App\DTO\Notifications\NotificationData;
use App\Enums\Notifications\NotificationUrgency;
use App\Events\Notifications\NotificationInboxChanged;
use App\Models\Notifications\AppNotification;
use App\Services\Notifications\AlertRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class SynchronizeOperationalAlertsAction
{
    public function __construct(
        private AlertRecipients $recipients,
        private PublishNotificationAction $publish,
        private ResolveAlertNotificationAction $resolve,
    ) {}

    /**
     * Sincroniza una evaluación ya serializada por tipo y unidad productiva.
     *
     * @param  array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}>  $pending
     */
    public function execute(int $unitId, string $module, string $type, string $permission, string $entityType, CarbonImmutable $evaluatedAt, array $pending): void
    {
        $recipients = $pending === [] ? [] : $this->recipients->ids($unitId, $permission);
        $open = [];
        $notices = AppNotification::query()->where('module', $module)->where('type', $type)
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
                    type: $type,
                    sourceKey: $key.':'.Str::uuid(),
                    title: $alert['title'],
                    message: $alert['message'],
                    occurredAt: $evaluatedAt,
                    productionUnitId: $unitId,
                    entityType: $entityType,
                    entityId: $alert['entity_id'],
                    targetPath: $alert['target_path'],
                    urgency: $alert['urgency'],
                    module: $module,
                    isAlert: true,
                ));
            }
        }
    }
}
