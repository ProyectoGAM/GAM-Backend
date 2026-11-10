<?php

namespace App\Services\AuditAndTraceability;

use App\Models\AuditAndTraceability\AuditEntry;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class WeighingAuditPresentation
{
    /** @var list<string> */
    private const EVENTS = ['weighing_recorded', 'weighing_corrected', 'daily_weighing_entry_added', 'daily_weighing_entry_deleted'];

    public function supports(?string $event): bool
    {
        return in_array($event, self::EVENTS, true);
    }

    /** @param array<string, mixed> $snapshot @return array{title: string, summary: string} */
    public function summary(AuditEntry $entry, array $snapshot): array
    {
        $daily = str_starts_with((string) $entry->event, 'daily_');
        $deleted = $entry->event === 'daily_weighing_entry_deleted';
        $sample = $daily ? $this->snapshotArray($snapshot[$deleted ? 'deleted_entry' : 'entry'] ?? null) : $snapshot;
        $mode = $this->mode($sample['mode'] ?? null);
        $adjective = ['Individual' => 'individual', 'Grupal' => 'grupal'][$mode ?? ''] ?? null;
        $title = $daily ? 'Ingreso'.($adjective === null ? '' : ' '.$adjective).' de pesaje diario '.($deleted ? 'eliminado' : 'registrado')
            : 'Pesaje'.($adjective === null ? '' : ' '.$adjective).' '.($entry->event === 'weighing_corrected' ? 'rectificado' : 'registrado');
        $parts = [];
        $birds = $this->count($daily ? ($sample['bird_count'] ?? null) : ($sample['represented_bird_count'] ?? null));
        if ($birds !== null) {
            $parts[] = $birds.($birds === '1' ? ' ave' : ' aves');
        }
        $total = $this->weight($sample['total_weight_g'] ?? null);
        $average = $this->weight($sample['average_weight_g'] ?? null);
        if ($total !== null) {
            $parts[] = 'Peso total: '.$total;
        }
        if ($average !== null) {
            $parts[] = 'Promedio: '.$average.' por ave';
        }
        $summary = $parts === [] ? $title.'.' : implode(' · ', $parts).'.';
        $range = $daily ? $this->snapshotArray($snapshot['daily_weighing'] ?? null) : $snapshot;
        $summary .= ' '.$this->evaluation($sample, $range['expected_range'] ?? null);
        if ($daily) {
            $old = $this->snapshotArray($entry->attribute_changes?->get('old'));
            $oldDaily = $this->snapshotArray($old['daily_weighing'] ?? $old);
            $after = $this->snapshotArray($snapshot['daily_weighing'] ?? null);
            $beforeCount = $this->count($oldDaily['represented_bird_count'] ?? null);
            $afterCount = $this->count($after['represented_bird_count'] ?? null);
            if ($afterCount === '0') {
                $summary .= ' La muestra diaria quedó vacía.';
            } elseif ($beforeCount !== null && $afterCount !== null) {
                $summary .= ' La muestra diaria pasó de '.$beforeCount.' a '.$afterCount.($afterCount === '1' ? ' ave.' : ' aves.');
            } elseif ($afterCount !== null) {
                $summary .= ' La muestra diaria contiene '.$afterCount.($afterCount === '1' ? ' ave.' : ' aves.');
            }
        }

        return ['title' => $title, 'summary' => $summary];
    }

    /** @param array<string, mixed> $snapshot @return array{facts: list<array{label: string, value: string}>, changes: list<array{label: string, before: ?string, after: ?string}>, changes_state: string, missing: list<string>} */
    public function detail(AuditEntry $entry, array $snapshot): array
    {
        $daily = str_starts_with((string) $entry->event, 'daily_');
        $deleted = $entry->event === 'daily_weighing_entry_deleted';
        $old = $this->snapshotArray($entry->attribute_changes?->get('old'));
        $new = $this->snapshotArray($entry->attribute_changes?->get('new'));
        $sample = $daily ? $this->snapshotArray($snapshot[$deleted ? 'deleted_entry' : 'entry'] ?? null) : $snapshot;
        $totals = $daily ? $this->snapshotArray($snapshot['daily_weighing'] ?? null) : $snapshot;
        $oldState = $daily ? $this->snapshotArray($old['daily_weighing'] ?? $old) : $old;
        $newState = $daily ? $this->snapshotArray($new['daily_weighing'] ?? null) : $new;
        $facts = $this->sampleValues($sample, $daily, $totals['expected_range'] ?? null);
        if ($daily) {
            $facts = [...$facts, ...$this->dailyValues($totals)];
            if (is_string($entry->properties?->get('reason'))) {
                $facts['Motivo de eliminación'] = trim(strip_tags($entry->properties->get('reason')));
            }
        } else {
            $measurements = $this->measurementValues($sample['measurements'] ?? null);
            foreach ($measurements as $index => $value) {
                $facts['Medición '.($index + 1)] = $value;
            }
        }
        $before = $daily ? $this->dailyValues($oldState) : $this->sampleValues($oldState, false, $oldState['expected_range'] ?? null);
        $after = $daily ? $this->dailyValues($newState) : $this->sampleValues($newState, false, $newState['expected_range'] ?? null);
        if (! $daily) {
            $oldMeasurements = $this->measurementValues($oldState['measurements'] ?? null);
            $newMeasurements = $this->measurementValues($newState['measurements'] ?? null);
            $before['Mediciones de la muestra'] = $oldMeasurements === [] ? null : implode('; ', $oldMeasurements);
            $after['Mediciones de la muestra'] = $newMeasurements === [] ? null : implode('; ', $newMeasurements);
        }
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $label) {
            $previous = $before[$label] ?? null;
            $current = $after[$label] ?? null;
            if ($previous !== $current) {
                $changes[] = ['label' => $label, 'before' => $previous, 'after' => $current];
            }
        }
        $missing = [];
        foreach (['mode' => 'la modalidad', ($daily ? 'bird_count' : 'represented_bird_count') => 'la cantidad de aves', 'total_weight_g' => 'el peso total', 'average_weight_g' => 'el peso promedio'] as $field => $label) {
            $value = $field === 'mode' ? $this->mode($sample[$field] ?? null) : ($field === 'bird_count' || $field === 'represented_bird_count' ? $this->count($sample[$field] ?? null) : $this->weight($sample[$field] ?? null));
            if ($value === null) {
                $missing[] = 'No se conserva '.$label.' del pesaje.';
            }
        }
        if ($daily && ($this->count($totals['represented_bird_count'] ?? null) === null || $this->weight($totals['total_weight_g'] ?? null) === null)) {
            $missing[] = 'No se conservan todos los totales de la muestra diaria.';
        }
        if ($this->range($totals['expected_range'] ?? null) !== null && ! is_bool($sample['outside_expected_range'] ?? null)) {
            $missing[] = 'No se conserva la evaluación del rango del pesaje.';
        }
        if ($daily && $entry->event === 'daily_weighing_entry_added' && $oldState === [] && $old !== []) {
            $missing[] = 'No se conserva la muestra diaria anterior.';
        }
        if (! $daily && is_string($entry->properties?->get('reason'))) {
            $facts['Motivo de rectificación'] = trim(strip_tags($entry->properties->get('reason')));
        }
        $facts = array_filter($facts, static fn (?string $value): bool => $value !== null);
        $presented = [];
        foreach ($facts as $label => $value) {
            $presented[] = ['label' => $label, 'value' => $value];
        }

        return [
            'facts' => $presented, 'changes' => $changes,
            'changes_state' => $old === [] && $new === [] ? 'not_recorded' : ($missing === [] ? 'presented' : ($changes === [] ? 'unavailable' : 'partial')),
            'missing' => $missing,
        ];
    }

    /** @param array<string, mixed> $sample @return array<string, ?string> */
    private function sampleValues(array $sample, bool $daily, mixed $range): array
    {
        if ($sample === []) {
            return [];
        }
        $average = $this->weight($sample['average_weight_g'] ?? null);

        return [
            'Modalidad' => $this->mode($sample['mode'] ?? null),
            $daily ? 'Aves del ingreso' : 'Cantidad de aves' => $this->count($sample[$daily ? 'bird_count' : 'represented_bird_count'] ?? null),
            $daily ? 'Peso total del ingreso' : 'Peso total' => $this->weight($sample['total_weight_g'] ?? null),
            $daily ? 'Promedio del ingreso' : 'Promedio por ave' => $average === null ? null : $average.' por ave',
            'Rango esperado por ave' => $this->range($range),
            'Evaluación del rango' => $this->evaluation($sample, $range),
            'Instante del pesaje' => $this->instant($sample['occurred_at'] ?? null),
            'Observaciones' => is_string($sample['notes'] ?? null) ? trim(strip_tags($sample['notes'])) : null,
        ];
    }

    /** @param array<string, mixed> $daily @return array<string, ?string> */
    private function dailyValues(array $daily): array
    {
        if ($daily === []) {
            return [];
        }
        $average = $this->weight($daily['average_weight_g'] ?? null);
        $count = $this->count($daily['represented_bird_count'] ?? null);
        $anomalies = $this->count($daily['anomalous_entry_count'] ?? null);

        return [
            'Cantidad de aves de la muestra diaria' => $count,
            'Peso total de la muestra diaria' => $this->weight($daily['total_weight_g'] ?? null),
            'Promedio de la muestra diaria' => $average === null ? ($count === '0' ? 'Sin aves en la muestra' : null) : $average.' por ave',
            'Ingresos fuera del rango esperado' => $anomalies,
            'Fecha de la muestra diaria' => $this->date($daily['date'] ?? null),
            'Rango esperado por ave' => $this->range($daily['expected_range'] ?? null),
        ];
    }

    private function mode(mixed $mode): ?string
    {
        return is_string($mode) ? (['individual' => 'Individual', 'group' => 'Grupal'][$mode] ?? null) : null;
    }

    private function count(mixed $value): ?string
    {
        return (is_int($value) || is_string($value)) && preg_match('/^\\d+$/', (string) $value) === 1 ? $this->number($value) : null;
    }

    private function weight(mixed $value): ?string
    {
        $number = $this->number($value);

        return $number === null ? null : $number.' g';
    }

    private function number(mixed $value): ?string
    {
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }
        try {
            $number = BigDecimal::of($value);
            if ($number->isNegative()) {
                return null;
            }
            $parts = explode('.', (string) $number->strippedOfTrailingZeros());

            return preg_replace('/(?<=\\d)(?=(?:\\d{3})+$)/', '.', $parts[0]).(isset($parts[1]) ? ','.$parts[1] : '');
        } catch (InvalidArgumentException|MathException) {
            return null;
        }
    }

    private function range(mixed $value): ?string
    {
        $range = $this->snapshotArray($value);
        $min = $this->weight($range['min_weight_g'] ?? null);
        $max = $this->weight($range['max_weight_g'] ?? null);

        return $min === null || $max === null ? null : $min.' – '.$max.' por ave';
    }

    /** @param array<string, mixed> $sample */
    private function evaluation(array $sample, mixed $range): string
    {
        if ($this->range($range) === null) {
            return 'Sin rango esperado disponible.';
        }
        $outside = $sample['outside_expected_range'] ?? null;
        if (! is_bool($outside)) {
            return 'Evaluación del rango no conservada.';
        }

        return $outside ? 'Una o más mediciones fuera del rango esperado.' : 'Dentro del rango esperado.';
    }

    private function instant(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/T.*(?:Z|[+-]\\d{2}:\\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) !== 1) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

            return $date !== null && $date->format('Y-m-d') === $value ? $date->format('d/m/Y') : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<string> */
    private function measurementValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $values = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }
            $weight = $this->weight($row['weight_g'] ?? null);
            if ($weight !== null) {
                $values[] = '1 ave · '.$weight;

                continue;
            }
            $birds = $this->count($row['bird_count'] ?? null);
            $total = $this->weight($row['total_weight_g'] ?? null);
            $average = $this->weight($row['average_weight_g'] ?? null);
            if ($birds !== null && $total !== null) {
                $values[] = $birds.' aves · '.$total.($average === null ? '' : ' · '.$average.' por ave');
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function snapshotArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
