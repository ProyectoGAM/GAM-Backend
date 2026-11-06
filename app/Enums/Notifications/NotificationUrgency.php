<?php

namespace App\Enums\Notifications;

enum NotificationUrgency: string
{
    case Low = 'bajo';
    case Medium = 'medio';
    case High = 'alto';
}
