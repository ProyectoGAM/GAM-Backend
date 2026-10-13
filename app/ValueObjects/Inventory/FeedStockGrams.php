<?php

namespace App\ValueObjects\Inventory;

use Brick\Math\BigInteger;
use InvalidArgumentException;

final readonly class FeedStockGrams
{
    public const MAXIMUM = 999999999999;

    /** Conserva el rango entero de decimal(18,6), sin casts de valores aproximados. */
    public static function parse(mixed $value, bool $positive = false, bool $allowNegative = true): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^-?[0-9]+$/D', (string) $value)) {
            throw new InvalidArgumentException('La cantidad debe expresarse en gramos enteros.');
        }
        $integer = BigInteger::of($value);
        if ($integer->abs()->compareTo(self::MAXIMUM) > 0) {
            throw new InvalidArgumentException('La cantidad excede el límite de 999999999999 gramos del inventario.');
        }
        if (($positive && $integer->compareTo(0) <= 0) || (! $allowNegative && $integer->isNegative())) {
            throw new InvalidArgumentException($positive ? 'La cantidad debe ser estrictamente positiva.' : 'El saldo de ingredientes no puede ser negativo.');
        }

        return $integer->toInt();
    }

    public static function adjusted(int $before, string $mode, int $value): int
    {
        $result = match ($mode) {
            'sumar' => $before + $value,
            'restar' => $before - $value,
            'establecer' => $value,
            default => throw new InvalidArgumentException('La acción debe ser sumar, restar o establecer.'),
        };

        return self::parse($result);
    }
}
