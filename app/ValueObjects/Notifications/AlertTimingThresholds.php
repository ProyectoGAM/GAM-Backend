<?php

namespace App\ValueObjects\Notifications;

use App\Enums\Notifications\NotificationUrgency;

final readonly class AlertTimingThresholds
{
    public function __construct(public ?int $low, public ?int $medium, public int $high) {}

    public function firstDay(): int
    {
        return $this->low ?? $this->medium ?? $this->high;
    }

    public function urgency(int $days): ?NotificationUrgency
    {
        return match (true) {
            $days >= $this->high => NotificationUrgency::High,
            $this->medium !== null && $days >= $this->medium => NotificationUrgency::Medium,
            $this->low !== null && $days >= $this->low => NotificationUrgency::Low,
            default => null,
        };
    }

    /** @return array{bajo: ?int, medio: ?int, alto: int} */
    public function toArray(): array
    {
        return ['bajo' => $this->low, 'medio' => $this->medium, 'alto' => $this->high];
    }
}
