<?php

namespace App\Modules\Inventory\Domain\ValueObjects;

use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

final readonly class InventoryQuantity
{
    public const SCALE = 6;

    private function __construct(private BigDecimal $value) {}

    public static function from(string|int $value, BaseUnit $unit, bool $allowNegative = true): self
    {
        try {
            $decimal = BigDecimal::of($value)->toScale(self::SCALE);
        } catch (MathException $exception) {
            throw new InvalidArgumentException('La cantidad debe ser un número decimal válido con hasta seis decimales.', previous: $exception);
        }

        if (! $allowNegative && $decimal->isNegative()) {
            throw new InvalidArgumentException('La cantidad no puede ser negativa.');
        }

        if (! $unit->allowsFraction()) {
            try {
                $integral = $decimal->toScale(0);
            } catch (MathException $exception) {
                throw new InvalidArgumentException('La unidad seleccionada no permite fracciones.', previous: $exception);
            }

            if ($integral->compareTo($decimal) !== 0) {
                throw new InvalidArgumentException('La unidad seleccionada no permite fracciones.');
            }
        }

        return new self($decimal);
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value)->toScale(self::SCALE));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value)->toScale(self::SCALE));
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }
}
