<?php

namespace App\Modules\AuditAndTraceability\Application\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AuditEntryData
{
    /**
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $attributeChanges
     */
    public function __construct(
        public string $logName,
        public string $event,
        public string $description,
        public string $operationId,
        public ?string $traceId,
        public string $source,
        public ?int $upId,
        public ?string $actorType,
        public int|string|null $actorId,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public array $properties = [],
        public array $attributeChanges = [],
    ) {}

    /**
     * Crea un registro para una entidad y conserva únicamente las propiedades
     * que el caso de uso decide exponer como instantánea histórica.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<string, mixed>  $attributeChanges
     */
    public static function forSubject(
        Model $subject,
        ?Model $actor,
        string $logName,
        string $event,
        string $description,
        array $properties = [],
        array $attributeChanges = [],
        ?int $upId = null,
        ?string $operationId = null,
        ?string $traceId = null,
        string $source = 'application',
    ): self {
        return new self(
            logName: $logName,
            event: $event,
            description: $description,
            operationId: $operationId ?? Str::uuid()->toString(),
            traceId: $traceId,
            source: $source,
            upId: $upId,
            actorType: $actor?->getMorphClass(),
            actorId: $actor === null ? null : self::normalizeKey($actor),
            subjectType: $subject->getMorphClass(),
            subjectId: self::normalizeKey($subject),
            properties: $properties,
            attributeChanges: $attributeChanges,
        );
    }

    private static function normalizeKey(Model $model): int|string|null
    {
        $key = $model->getKey();

        if ($key === null || is_int($key) || is_string($key)) {
            return $key;
        }

        throw new InvalidArgumentException('Los sujetos y actores de auditoría deben tener claves escalares.');
    }
}
