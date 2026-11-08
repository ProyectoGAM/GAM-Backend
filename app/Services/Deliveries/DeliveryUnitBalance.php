<?php

namespace App\Services\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\Deliveries\Delivery;
use Illuminate\Validation\ValidationException;

final class DeliveryUnitBalance
{
    public function canReturnTo(Delivery $delivery, int $unitId): bool
    {
        $origins = $delivery->loadMissing('loads')->loads->pluck('production_unit_id')->filter();

        return $origins->isEmpty() ? $delivery->production_unit_id === $unitId : $origins->contains($unitId);
    }

    /** @return array{rows:list<array<string, mixed>>,unallocated_delivered_eggs:int,unallocated_returned_eggs:int} */
    public function summarize(Delivery $delivery): array
    {
        $rows = [];
        foreach ($delivery->loads as $load) {
            $items = $load->items ?? [[
                'unit' => 'huevo', 'label' => 'Huevos', 'eggs_per_unit' => 1,
                'amount' => (string) $load->quantity,
            ]];
            foreach ($items as $item) {
                $key = $this->key($item);
                $rows[$key] ??= [
                    'unit' => $item['unit'], 'label' => $item['label'],
                    'eggs_per_unit' => (int) $item['eggs_per_unit'],
                    'loaded_milli' => 0, 'delivered_milli' => 0, 'returned_milli' => 0,
                ];
                $rows[$key]['loaded_milli'] += $this->milli((string) $item['amount']);
            }
        }

        $unallocated = 0;
        foreach ($delivery->stops as $stop) {
            if ($stop->status !== DeliveryStopStatus::Delivered) {
                continue;
            }
            if ($stop->items === null) {
                $unallocated += $stop->delivered_quantity;

                continue;
            }
            foreach ($stop->items as $item) {
                $key = $this->key($item);
                if (isset($rows[$key])) {
                    $rows[$key]['delivered_milli'] += $this->milli((string) $item['amount']);
                }
            }
        }

        $allocatedReturn = 0;
        foreach ($delivery->returned_items ?? [] as $item) {
            $key = $this->key($item);
            $amount = $this->milli((string) $item['amount']);
            $allocatedReturn += intdiv($amount * (int) $item['eggs_per_unit'], 1000);
            if (isset($rows[$key])) {
                $rows[$key]['returned_milli'] += $amount;
            }
        }
        $unallocatedReturn = max(0, (int) $delivery->returned_quantity - $allocatedReturn);

        return [
            'rows' => array_values(array_map(function (array $row) use ($unallocated, $unallocatedReturn): array {
                $available = $row['loaded_milli'] - $row['delivered_milli'] - $row['returned_milli'];
                $eggsPerUnit = $row['eggs_per_unit'];

                return [
                    'unit' => $row['unit'], 'label' => $row['label'], 'eggs_per_unit' => $eggsPerUnit,
                    'loaded_amount' => $this->amount($row['loaded_milli']),
                    'delivered_amount' => $this->amount($row['delivered_milli']),
                    'returned_amount' => $this->amount($row['returned_milli']),
                    'remaining_amount' => $unallocated === 0 && $unallocatedReturn === 0 ? $this->amount($available) : null,
                    'loaded_eggs' => intdiv($row['loaded_milli'] * $eggsPerUnit, 1000),
                    'delivered_eggs' => intdiv($row['delivered_milli'] * $eggsPerUnit, 1000),
                ];
            }, $rows)),
            'unallocated_delivered_eggs' => $unallocated,
            'unallocated_returned_eggs' => $unallocatedReturn,
        ];
    }

    /**
     * @param  list<array{unit:string,amount:string,eggs_per_unit:int}>  $requested
     * @return array{quantity:int,items:list<array{unit:string,label:string,amount:string,eggs_per_unit:int,eggs:int}>}
     */
    public function allocate(Delivery $delivery, array $requested): array
    {
        $summary = $this->summarize($delivery);
        if ($summary['unallocated_delivered_eggs'] > 0) {
            throw ValidationException::withMessages(['items' => 'Este reparto tiene entregas anteriores sin presentación; no se puede verificar el saldo por unidad.']);
        }

        $available = [];
        foreach ($summary['rows'] as $row) {
            $available[$this->key($row)] = $row;
        }
        $requestedByKey = [];
        foreach ($requested as $index => $item) {
            $key = $this->key($item);
            if (! isset($available[$key])) {
                throw ValidationException::withMessages(["items.$index.unit" => 'La presentación o su equivalencia no están cargadas en este reparto.']);
            }
            $requestedByKey[$key] = ($requestedByKey[$key] ?? 0) + $this->milli($item['amount']);
        }

        $items = [];
        $quantity = 0;
        foreach ($requestedByKey as $key => $milli) {
            $row = $available[$key];
            if ($milli < 1 || $milli > intdiv(2147483647 * 1000, $row['eggs_per_unit'])) {
                throw ValidationException::withMessages(['items' => 'La cantidad debe ser positiva y no superar el máximo de huevos permitido.']);
            }
            $eggsScaled = $milli * $row['eggs_per_unit'];
            if ($eggsScaled % 1000 !== 0) {
                throw ValidationException::withMessages(['items' => 'Cada presentación debe equivaler a huevos enteros.']);
            }
            $eggs = intdiv($eggsScaled, 1000);
            $quantity += $eggs;
            if ($quantity > 2147483647) {
                throw ValidationException::withMessages(['items' => 'La entrega supera el máximo de huevos permitido.']);
            }
            $items[] = [
                'unit' => $row['unit'], 'label' => $row['label'],
                'amount' => $this->amount($milli), 'eggs_per_unit' => $row['eggs_per_unit'], 'eggs' => $eggs,
            ];
        }

        return ['quantity' => $quantity, 'items' => $items];
    }

    /** @param array{unit:string,eggs_per_unit:int} $item */
    private function key(array $item): string
    {
        return $item['unit'].'|'.$item['eggs_per_unit'];
    }

    private function milli(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
    }

    private function amount(int $milli): string
    {
        $magnitude = abs($milli);

        return ($milli < 0 ? '-' : '').rtrim(rtrim(sprintf('%d.%03d', intdiv($magnitude, 1000), $magnitude % 1000), '0'), '.');
    }
}
