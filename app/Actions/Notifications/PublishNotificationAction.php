<?php

namespace App\Actions\Notifications;

use App\DTO\Notifications\NotificationData;
use App\Events\Notifications\NotificationInboxChanged;
use App\Models\Notifications\AppNotification;
use Illuminate\Support\Str;

final class PublishNotificationAction
{
    public function execute(NotificationData $data): AppNotification
    {
        $notification = AppNotification::query()->firstOrCreate([
            'recipient_id' => $data->recipientId,
            'type' => $data->type,
            'source_key' => $data->sourceKey,
        ], [
            'public_id' => (string) Str::ulid(),
            'production_unit_id' => $data->productionUnitId,
            'urgency' => $data->urgency,
            'module' => $data->module,
            'is_alert' => $data->isAlert,
            'title' => $data->title,
            'message' => $data->message,
            'entity_type' => $data->entityType,
            'entity_id' => $data->entityId,
            'target_path' => $data->targetPath,
            'occurred_at' => $data->occurredAt,
        ]);

        if ($notification->wasRecentlyCreated) {
            NotificationInboxChanged::dispatch($data->recipientId, $data->productionUnitId);
        }

        return $notification;
    }
}
