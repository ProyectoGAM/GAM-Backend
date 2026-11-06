<?php

namespace App\DTO\Notifications;

use App\Enums\Notifications\NotificationUrgency;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class NotificationData
{
    public const string MODULE_PATTERN = '/^[a-z][a-z0-9_]{0,79}$/D';

    public function __construct(
        public int $recipientId,
        public string $type,
        public string $sourceKey,
        public string $title,
        public string $message,
        public DateTimeInterface $occurredAt,
        public ?int $productionUnitId = null,
        public ?string $entityType = null,
        public ?string $entityId = null,
        public ?string $targetPath = null,
        public NotificationUrgency $urgency = NotificationUrgency::Low,
        public string $module = 'general',
    ) {
        if (preg_match(self::MODULE_PATTERN, $module) !== 1) {
            throw new InvalidArgumentException('El módulo debe ser un código en minúsculas de hasta 80 caracteres.');
        }

        if (($entityType === null) !== ($entityId === null)
            || ($targetPath !== null && ($entityId === null || preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $targetPath) !== 1))) {
            throw new InvalidArgumentException('La referencia de la notificación debe identificar un registro y usar una ruta interna válida.');
        }
    }
}
