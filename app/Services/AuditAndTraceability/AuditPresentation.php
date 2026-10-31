<?php

namespace App\Services\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Inventory\InventoryMovement;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AuditPresentation
{
    /** @var array<string, string> */
    private const FIELDS = [
        'name' => 'Nombre', 'code' => 'Código', 'status' => 'Estado',
        'mode' => 'Modo de alimentación', 'age_days' => 'Edad en días', 'population' => 'Cantidad de aves',
        'quantity_grams' => 'Cantidad de ración', 'daily_quantity_grams' => 'Cantidad diaria de ración',
        'grams_per_bird' => 'Ración por ave', 'consumption_date' => 'Fecha de consumo',
        'effective_date' => 'Fecha de vigencia', 'maintenance_date' => 'Fecha de mantenimiento',
        'quantity' => 'Cantidad', 'initial_quantity' => 'Cantidad inicial de aves', 'current_quantity' => 'Cantidad actual de aves',
        'minimum_quantity_grams' => 'Mínimo de alimento', 'reason' => 'Motivo', 'notes' => 'Observaciones',
        'description' => 'Descripción', 'responsible_name' => 'Responsable',
        'medicine.name' => 'Medicamento', 'vaccine.name' => 'Vacuna', 'product.name' => 'Producto',
        'supplier.name' => 'Proveedor', 'responsible.name_at_application' => 'Responsable de la aplicación',
        'plan_activity_title_at_application' => 'Actividad del plan', 'cancellation_reason' => 'Motivo de cancelación',
        'stage_snapshot.recipe_snapshot.name' => 'Receta copiada en la etapa',
        'occurred_at' => 'Instante de la operación', 'cancelled_at' => 'Instante de cancelación',
        'stage_snapshot.grams_per_bird' => 'Ración por ave de la etapa',
        'stage_snapshot.start_day' => 'Día de inicio de la etapa', 'census_snapshot.population' => 'Censo de aves',
    ];

    public function __construct(private AuditEventCatalog $events, private AuditEntryActions $actions, private WeighingAuditPresentation $weighings) {}

    /** @return array<string, mixed> */
    public function summary(AuditEntry $entry): array
    {
        $snapshot = $this->snapshot($entry);
        $subject = $this->subject($entry, $snapshot);
        $title = $this->events->label($entry->event, $entry->description);
        $quantity = $this->format('quantity_grams', $snapshot['quantity_grams'] ?? null);
        $population = $this->format('population', $snapshot['population'] ?? null);
        $summary = $title.'.';
        if ($entry->event === 'feed_consumption_recorded' && $quantity !== null) {
            $summary = 'Se registraron '.$quantity.($population === null ? '' : ' para '.$population.' aves')
                .($subject['label'] === null ? '.' : ' del lote '.$subject['label'].'.');
        } elseif ($entry->event === 'feed_consumption_corrected' && $quantity !== null) {
            $summary = 'El consumo rectificado es de '.$quantity.'.';
        }
        if ($this->weighings->supports($entry->event)) {
            $weighing = $this->weighings->summary($entry, $snapshot);
            $title = $weighing['title'];
            $summary = $weighing['summary'];
        }

        return ['title' => $title, 'summary' => $summary, 'subject' => $subject,
            'production_unit' => $this->productionUnit($entry)];
    }

    /** @return array<string, mixed> */
    public function detail(AuditEntry $entry, User $viewer): array
    {
        $snapshot = $this->snapshot($entry);
        if ($this->weighings->supports($entry->event)) {
            $weighing = $this->weighings->detail($entry, $snapshot);
            $missing = [...$this->contextMissing($entry), ...$weighing['missing']];

            return [...$this->summary($entry), 'facts' => $weighing['facts'], 'changes' => $weighing['changes'],
                'changes_state' => $weighing['changes_state'],
                'history' => ['state' => $missing === [] ? 'complete' : 'partial', 'missing' => $missing],
                ...$this->actions->forEntry($entry, $viewer)];
        }
        $facts = [];
        foreach (self::FIELDS as $field => $label) {
            $value = $this->format($field, data_get($snapshot, $field));
            if ($value !== null) {
                $facts[] = ['label' => $label, 'value' => $value];
            }
        }
        $context = $entry->properties?->get('presentation_snapshot') ?? [];
        foreach (['recipe_name' => 'Receta', 'poultry_house_name' => 'Galpón'] as $key => $label) {
            if (is_string($context[$key] ?? null)) {
                $facts[] = ['label' => $label, 'value' => $this->text($context[$key])];
            }
        }
        $old = $entry->attribute_changes?->get('old') ?? [];
        $new = $entry->attribute_changes?->get('new') ?? [];
        $old = is_array($old) ? $old : [];
        $new = is_array($new) ? $new : [];
        $changes = [];
        foreach (self::FIELDS as $field => $label) {
            if (! Arr::has($old, $field) && ! Arr::has($new, $field)) {
                continue;
            }
            $before = $this->format($field, data_get($old, $field));
            $after = $this->format($field, data_get($new, $field));
            if ($before !== $after) {
                $changes[] = ['label' => $label, 'before' => $before, 'after' => $after];
            }
        }
        if (isset($context['recipe_name']) && ($context['before_recipe_name'] ?? null) !== $context['recipe_name']) {
            $changes[] = ['label' => 'Receta', 'before' => isset($context['before_recipe_name']) ? $this->text($context['before_recipe_name']) : null,
                'after' => $this->text($context['recipe_name'])];
        }
        $balances = is_array($snapshot['balances'] ?? null) ? $snapshot['balances'] : [];
        foreach ($balances as $balance) {
            if (! is_array($balance) || ! is_string($balance['product_name'] ?? null) || ! is_string($balance['stock_location_name'] ?? null)) {
                continue;
            }
            $before = $this->quantity($balance['before'] ?? null, $balance['unit'] ?? null);
            $after = $this->quantity($balance['after'] ?? null, $balance['unit'] ?? null);
            if ($before !== null && $after !== null && $before !== $after) {
                $changes[] = ['label' => 'Existencias de '.$this->text($balance['product_name']).' en '.$this->text($balance['stock_location_name']),
                    'before' => $before, 'after' => $after];
            }
        }
        $unmappedChanges = array_diff(array_unique([...array_keys(Arr::dot($old)), ...array_keys(Arr::dot($new))]), array_keys(self::FIELDS)) !== [];
        $missing = $this->contextMissing($entry);
        if (str_starts_with((string) $entry->event, 'feed_consumption_') && ! isset($context['recipe_name'])) {
            $missing[] = 'No se conserva el nombre histórico de la receta.';
        }
        if ($changes === [] && ($old !== [] || $new !== [])) {
            $missing[] = 'Los cambios registrados no tienen una presentación disponible.';
        } elseif ($changes !== [] && $unmappedChanges) {
            $missing[] = 'Se presentan los cambios reconocidos; otros datos del registro no se muestran.';
        }

        return [...$this->summary($entry), 'facts' => $facts, 'changes' => $changes,
            'changes_state' => $changes !== [] ? ($unmappedChanges ? 'partial' : 'presented') : (($old !== [] || $new !== []) ? 'unavailable' : 'not_recorded'),
            'history' => ['state' => $missing === [] ? 'complete' : 'partial', 'missing' => $missing],
            ...$this->actions->forEntry($entry, $viewer)];
    }

    /** @return list<string> */
    private function contextMissing(AuditEntry $entry): array
    {
        $missing = [];
        if ($entry->causer_id !== null && ! is_string($entry->properties?->get('actor_snapshot')['name'] ?? null)) {
            $missing[] = 'No se conserva el nombre histórico del responsable.';
        }
        if ($this->summary($entry)['subject']['label'] === null) {
            $missing[] = 'No se conserva el nombre histórico del recurso.';
        }
        if ($entry->up_id !== null && $entry->properties?->get('production_unit_snapshot') === null) {
            $missing[] = 'No se conserva el nombre histórico de la unidad productiva.';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function snapshot(AuditEntry $entry): array
    {
        $snapshot = $entry->properties?->get('subject_snapshot') ?? $entry->attribute_changes?->get('new') ?? [];

        return is_array($snapshot) ? $snapshot : [];
    }

    /** @return array{id: int, name: string}|null */
    private function productionUnit(AuditEntry $entry): ?array
    {
        $unit = $entry->properties?->get('production_unit_snapshot');

        return is_array($unit) && is_int($unit['id'] ?? null) && is_string($unit['name'] ?? null)
            ? ['id' => $unit['id'], 'name' => $this->text($unit['name'])] : null;
    }

    /** @param array<string, mixed> $snapshot @return array{label: ?string, resource: ?string, id: int|string|null} */
    private function subject(AuditEntry $entry, array $snapshot): array
    {
        $weighingFlockId = match ($entry->event) {
            'weighing_recorded', 'weighing_corrected', 'mortality_recorded', 'mortality_corrected', 'mortality_cancelled' => $snapshot['flock_id'] ?? null,
            'flock_mortality_applied', 'flock_mortality_corrected' => $snapshot['public_id'] ?? null,
            'daily_weighing_entry_added', 'daily_weighing_entry_deleted' => data_get($snapshot, 'daily_weighing.flock_id'),
            default => null,
        };
        if (Str::isUlid($weighingFlockId)) {
            return ['label' => null, 'resource' => 'flock', 'id' => $weighingFlockId];
        }

        $subject = $entry->properties?->get('presentation_snapshot')['subject'] ?? $entry->properties?->get('presentation_subject');
        if (is_array($subject)) {
            $resource = $subject['resource'] ?? null;
            $identifier = $subject['id'] ?? null;
            $knownResource = is_string($resource) && in_array($resource, [
                'user', 'flock', 'production_unit', 'poultry_house', 'department', 'locality',
                'feed_recipe', 'stock_location', 'egg_presentation', 'medicine', 'vaccine',
                'product', 'supplier', 'customer', 'inventory_movement',
            ], true);
            $knownIdentifier = is_int($identifier) || is_string($identifier);

            return ['label' => is_string($subject['label'] ?? null) ? $this->text($subject['label']) : null,
                'resource' => $knownResource && $knownIdentifier ? $resource : null, 'id' => $knownResource && $knownIdentifier ? $identifier : null];
        }

        $label = $snapshot['name'] ?? $snapshot['code'] ?? null;
        if ($entry->subject_type === (new InventoryMovement)->getMorphClass()) {
            return ['label' => 'Movimiento de inventario', 'resource' => 'inventory_movement', 'id' => $entry->subject_id];
        }

        return ['label' => is_string($label) ? $this->text($label) : null, 'resource' => null, 'id' => null];
    }

    private function format(string $field, mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        if (in_array($field, ['quantity_grams', 'daily_quantity_grams', 'minimum_quantity_grams', 'grams_per_bird', 'stage_snapshot.grams_per_bird'], true)) {
            return $this->quantity($value, 'g');
        }
        if ($field === 'mode') {
            return ['automatic' => 'Automático', 'manual' => 'Manual'][$value] ?? null;
        }
        if ($field === 'status') {
            return ['active' => 'Activo', 'inactive' => 'Inactivo', 'operational' => 'Operativo', 'maintenance' => 'En mantenimiento',
                'out_of_service' => 'Fuera de servicio', 'finished' => 'Finalizado', 'quarantined' => 'En cuarentena',
                'cancelled' => 'Cancelado', 'scheduled' => 'Programado', 'completed' => 'Completado'][$value] ?? null;
        }
        if (in_array($field, ['consumption_date', 'effective_date', 'maintenance_date'], true)) {
            $date = substr((string) $value, 0, 10);
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date) !== 1) {
                return null;
            }
            try {
                $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
            } catch (InvalidArgumentException) {
                return null;
            }

            return $parsed !== null && $parsed->format('Y-m-d') === $date ? $parsed->format('d/m/Y') : null;
        }
        if (in_array($field, ['occurred_at', 'cancelled_at'], true)) {
            if (! is_string($value) || preg_match('/T.*(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
                return null;
            }
            try {
                return CarbonImmutable::parse($value)->toIso8601String();
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        return is_bool($value) ? ($value ? 'Sí' : 'No') : $this->text((string) $value);
    }

    private function quantity(mixed $value, mixed $unit): ?string
    {
        if ((! is_string($value) && ! is_int($value)) || ! in_array($unit, ['g', 'kg', 'unit', 'dose', 'l', 'ml'], true)) {
            return null;
        }
        try {
            $number = BigDecimal::of($value);
            if ($unit === 'g') {
                $number = $number->withPointMovedLeft(3);
            }
            $unitLabel = ['g' => 'kg', 'kg' => 'kg', 'unit' => 'unidades', 'dose' => 'dosis', 'l' => 'l', 'ml' => 'ml'][$unit];

            return str_replace('.', ',', (string) $number->strippedOfTrailingZeros()).' '.$unitLabel;
        } catch (InvalidArgumentException|MathException) {
            return null;
        }
    }

    private function text(string $value): string
    {
        return trim(strip_tags($value));
    }
}
