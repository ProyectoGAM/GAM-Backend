<?php

namespace App\Services\Deliveries;

use App\Enums\Deliveries\DeliveryStopStatus;
use App\Models\Deliveries\DeliveryStop;
use App\Models\Inventory\EggPresentation;
use App\ValueObjects\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money as BrickMoney;
use Illuminate\Validation\ValidationException;

final class DeliveryPricing
{
    /** @param iterable<DeliveryStop> $stops */
    public function total(iterable $stops): ?string
    {
        $total = BrickMoney::of(0, Money::CURRENCY, new CustomContext(3));
        foreach ($stops as $stop) {
            if ($stop->status !== DeliveryStopStatus::Delivered) {
                continue;
            }
            if ($stop->total_amount === null) {
                return null;
            }
            $total = $total->plus($stop->total_amount);
        }

        return (string) $total->getAmount();
    }

    /**
     * @param  list<array<string, mixed>>  $allocated
     * @param  list<array<string, mixed>>  $requested
     * @return array{items:list<array<string,mixed>>,total_amount:string}
     */
    public function calculate(array $allocated, array $requested): array
    {
        $definitions = EggPresentation::query()->whereIn('code', array_column($requested, 'unit'))->get()->keyBy('code');
        $prices = [];
        foreach ($requested as $index => $item) {
            $key = $item['unit'].'|'.$item['eggs_per_unit'];
            $definition = $definitions->get($item['unit']);
            $price = $item['unit_price'] ?? ($definition?->eggs_per_unit === (int) $item['eggs_per_unit'] ? $definition->default_unit_price : null);
            if ($price === null) {
                throw ValidationException::withMessages(["items.$index.unit_price" => 'Indicá el precio en pesos enteros para esta entrega; la presentación no tiene un precio predeterminado compatible.']);
            }
            if (isset($prices[$key]) && $prices[$key] !== (int) $price) {
                throw ValidationException::withMessages(["items.$index.unit_price" => 'Cada presentación debe tener un único precio unitario en esta entrega.']);
            }
            $prices[$key] = (int) $price;
        }

        $context = new CustomContext(3);
        $total = BrickMoney::of(0, Money::CURRENCY, $context);
        $items = [];
        foreach ($allocated as $item) {
            $price = $prices[$item['unit'].'|'.$item['eggs_per_unit']];
            $line = BrickMoney::of($price, Money::CURRENCY, $context)->multipliedBy($item['amount']);
            $total = $total->plus($line);
            $items[] = [...$item, 'unit_price' => $price, 'line_amount' => (string) $line->getAmount()];
        }

        return ['items' => $items, 'total_amount' => (string) $total->getAmount()];
    }

    /**
     * @param  list<array<string, mixed>>  $original
     * @param  list<array{indice: int, cantidad: string}>  $reductions
     * @return array{quantity: int, items: list<array<string, mixed>>, total_amount: string|null}
     */
    public function reduce(array $original, array $reductions): array
    {
        $changes = array_column($reductions, 'cantidad', 'indice');
        if (array_diff_key($changes, $original) !== []) {
            throw ValidationException::withMessages(['lineas' => 'La rectificación contiene una línea que no pertenece a la entrega.']);
        }

        $context = new CustomContext(3);
        $total = BrickMoney::of(0, Money::CURRENCY, $context);
        $priced = true;
        $items = [];
        $quantity = 0;
        foreach ($original as $index => $item) {
            $previous = BigDecimal::of($item['amount']);
            $amount = BigDecimal::of($changes[$index] ?? $item['amount']);
            if ($amount->isNegative() || $amount->isGreaterThan($previous)) {
                throw ValidationException::withMessages(['lineas' => 'La cantidad rectificada no puede superar la cantidad original.']);
            }
            if ($amount->isZero()) {
                continue;
            }

            try {
                $eggs = $amount->multipliedBy($item['eggs_per_unit'])->toInt();
            } catch (MathException) {
                throw ValidationException::withMessages(['lineas' => 'La cantidad rectificada debe equivaler a huevos enteros.']);
            }
            $item['amount'] = (string) $amount;
            $item['eggs'] = $eggs;
            $quantity += $eggs;
            if (isset($item['unit_price'])) {
                $line = BrickMoney::of($item['unit_price'], Money::CURRENCY, $context)->multipliedBy($amount);
                $item['line_amount'] = (string) $line->getAmount();
                $total = $total->plus($line);
            } else {
                $priced = false;
            }
            $items[] = $item;
        }

        return ['quantity' => $quantity, 'items' => $items, 'total_amount' => $priced ? (string) $total->getAmount() : null];
    }
}
