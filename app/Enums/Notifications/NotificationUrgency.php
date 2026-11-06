<?php

namespace App\Enums\Notifications;

enum NotificationUrgency: string
{
    case Low = 'bajo';
    case Medium = 'medio';
    case High = 'alto';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }
}
