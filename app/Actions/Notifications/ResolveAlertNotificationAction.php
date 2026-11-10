<?php

namespace App\Actions\Notifications;

use App\Events\Notifications\NotificationInboxChanged;
use App\Models\Notifications\AppNotification;

final class ResolveAlertNotificationAction
{
    /** Sincroniza la resolución en la bandeja desde la operación del módulo de origen. */
    public function execute(AppNotification $notification): AppNotification
    {
        $query = AppNotification::query()->whereKey($notification->getKey())->where('is_alert', true);
        $changed = (clone $query)->whereNull('resolved_at')->update(['resolved_at' => now()->toIso8601String()]);
        $resolved = $query->firstOrFail();

        if ($changed === 1) {
            NotificationInboxChanged::dispatch($resolved->recipient_id, $resolved->production_unit_id);
        }

        return $resolved;
    }
}
