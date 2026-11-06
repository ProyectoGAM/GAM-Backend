<?php

namespace App\DTO\Notifications;

use DateTimeInterface;
use InvalidArgumentException;

final readonly class NotificationData
{
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
    ) {
        if (($entityType === null) !== ($entityId === null)
            || ($targetPath !== null && ($entityId === null || preg_match('~^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$~D', $targetPath) !== 1))) {
            throw new InvalidArgumentException('La referencia de la notificación debe identificar un registro y usar una ruta interna válida.');
        }
    }
}
