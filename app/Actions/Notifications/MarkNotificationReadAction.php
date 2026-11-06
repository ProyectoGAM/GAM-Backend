<?php

namespace App\Actions\Notifications;

use App\Events\Notifications\NotificationInboxChanged;
use App\Models\Notifications\AppNotification;
use App\Models\User;

final class MarkNotificationReadAction
{
    public function execute(User $actor, string $publicId): AppNotification
    {
        $query = AppNotification::inboxFor($actor)->where('public_id', $publicId);
        $changed = (clone $query)->whereNull('read_at')->update(['read_at' => now()->toIso8601String()]);
        $notification = $query->firstOrFail();

        if ($changed === 1) {
            NotificationInboxChanged::dispatch($actor->id, $notification->production_unit_id);
        }

        return $notification;
    }
}
