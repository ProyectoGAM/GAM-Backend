<?php

namespace App\DTO\AuditAndTraceability;

use App\Models\CustomersAndAccounts\Customer;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Geography\Department;
use App\Models\Geography\Locality;
use App\Models\IdentityAndAccess\Role;
use App\Models\Inventory\EggPresentation;
use App\Models\Inventory\FeedRecipe;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Supplier;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
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
            properties: [...$properties, 'presentation_subject' => self::presentationSubject($subject, $properties), 'actor_snapshot' => [
                'kind' => $actor instanceof User ? 'person' : ($actor === null && ($source === 'scheduler' || ($properties['automatic_process'] ?? false) === true) ? 'system' : 'unavailable'),
                'name' => $actor instanceof User ? $actor->name : null,
            ]],
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

    /** @param array<string, mixed> $properties @return array{resource: string, id: int|string|null, label: string|null}|null */
    private static function presentationSubject(Model $subject, array $properties): ?array
    {
        $resources = [
            User::class => 'user',
            Role::class => 'role',
            Flock::class => 'flock',
            ProductionUnit::class => 'production_unit',
            PoultryHouse::class => 'poultry_house',
            Department::class => 'department',
            Locality::class => 'locality',
            FeedRecipe::class => 'feed_recipe',
            StockLocation::class => 'stock_location',
            EggPresentation::class => 'egg_presentation',
            Medicine::class => 'medicine',
            Vaccine::class => 'vaccine',
            Product::class => 'product',
            Supplier::class => 'supplier',
            Customer::class => 'customer',
        ];
        $resource = $resources[$subject::class] ?? null;
        if ($resource === null) {
            return null;
        }
        $attribute = match ($resource) {
            'flock' => 'code', 'role' => 'label', default => 'name'
        };
        $label = array_key_exists($attribute, $subject->getAttributes()) ? $subject->getAttribute($attribute) : null;
        $label ??= $properties['subject_snapshot']['name'] ?? $properties['subject_snapshot']['product']['name'] ?? null;

        return ['resource' => $resource, 'id' => $subject->getRouteKey(), 'label' => is_string($label) ? $label : null];
    }
}
