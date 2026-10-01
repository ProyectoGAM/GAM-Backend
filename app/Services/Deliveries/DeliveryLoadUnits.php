<?php

namespace App\Services\Deliveries;

use Illuminate\Validation\ValidationException;

final class DeliveryLoadUnits
{
    /** @return list<array{id:string,label:string,category:string,eggs_per_unit:int}> */
    public function catalog(): array
    {
        $result = [];
        foreach (config('delivery_units') as $id => $unit) {
            $result[] = ['id' => $id, 'label' => $unit['label'], 'category' => $unit['category'], 'eggs_per_unit' => $unit['eggs_per_unit']];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{quantity:int,items:list<array{unit:string,label:string,category:string,amount:string,eggs_per_unit:int,eggs:int}>}
     */
    public function calculate(array $data): array
    {
        if (! isset($data['items'])) {
            $quantity = (int) $data['quantity'];

            return ['quantity' => $quantity, 'items' => [[
                'unit' => 'huevo', 'label' => 'Huevos', 'category' => 'huevos', 'amount' => (string) $quantity,
                'eggs_per_unit' => 1, 'eggs' => $quantity,
            ]]];
        }

        $total = 0;
        $items = [];
        foreach ($data['items'] as $index => $item) {
            $unit = config('delivery_units.'.$item['unit']);
            if ($unit === null || (int) $item['eggs_per_unit'] !== $unit['eggs_per_unit']) {
                throw ValidationException::withMessages([
                    "items.$index.eggs_per_unit" => 'La equivalencia cambió. Actualizá las unidades antes de guardar esta carga.',
                ]);
            }

            $amount = (string) $item['amount'];
            if (! preg_match('/^\d{1,10}(?:\.\d{1,3})?$/D', $amount)) {
                throw ValidationException::withMessages(["items.$index.amount" => 'Cantidad inválida. Usá hasta tres decimales.']);
            }
            [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
            $thousandths = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
            $eggsScaled = $thousandths * $unit['eggs_per_unit'];
            if ($thousandths === 0 || $eggsScaled % 1000 !== 0) {
                throw ValidationException::withMessages(["items.$index.amount" => 'La cantidad debe equivaler a huevos enteros.']);
            }
            $eggs = intdiv($eggsScaled, 1000);
            $total += $eggs;
            if ($total > 2147483647) {
                throw ValidationException::withMessages(['items' => 'La carga supera el máximo de huevos permitido.']);
            }
            $items[] = [
                'unit' => $item['unit'], 'label' => $unit['label'], 'category' => $unit['category'], 'amount' => $amount,
                'eggs_per_unit' => $unit['eggs_per_unit'], 'eggs' => $eggs,
            ];
        }

        return ['quantity' => $total, 'items' => $items];
    }
}
