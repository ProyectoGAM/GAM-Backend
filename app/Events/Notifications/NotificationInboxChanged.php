<?php

namespace App\Events\Notifications;

use App\Services\Notifications\NotificationChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

final class NotificationInboxChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(private int $recipientId, private ?int $unitId) {}

    public function broadcastAs(): string
    {
        return 'notifications.changed';
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return app(NotificationChannel::class)->forRecipient($this->recipientId, $this->unitId);
    }

    /** @return array{} */
    public function broadcastWith(): array
    {
        return [];
    }
}
