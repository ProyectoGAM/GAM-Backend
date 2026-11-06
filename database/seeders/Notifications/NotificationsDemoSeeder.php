<?php

namespace Database\Seeders\Notifications;

use App\Actions\Notifications\PublishNotificationAction;
use App\DTO\Notifications\NotificationData;
use App\Models\User;
use Illuminate\Database\Seeder;

final class NotificationsDemoSeeder extends Seeder
{
    public function run(PublishNotificationAction $publish): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $admin = User::query()->role('admin')->orderBy('id')->firstOrFail();
        $publish->execute(new NotificationData(
            recipientId: $admin->id,
            type: 'demo',
            sourceKey: 'local-inbox-welcome',
            title: 'Tu bandeja de notificaciones',
            message: 'Acá vas a encontrar los avisos de GAM.',
            occurredAt: now(),
        ));
    }
}
