<?php

namespace App\ValueObjects\Inventory;

use InvalidArgumentException;

final readonly class RecipeGrams
{
    private function __construct(public int $value) {}

    /** Valida antes del cast para impedir truncamientos, floats y desbordamientos. */
    public static function from(mixed $value): self
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new InvalidArgumentException('La cantidad debe expresarse en gramos enteros positivos.');
        }
        $digits = (string) $value;
        if (! preg_match('/^[0-9]+$/D', $digits)) {
            throw new InvalidArgumentException('La cantidad debe expresarse en gramos enteros positivos.');
        }
        $digits = ltrim($digits, '0');
        $maximum = (string) PHP_INT_MAX;
        if ($digits === '' || strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            throw new InvalidArgumentException('La cantidad debe estar entre 1 y '.PHP_INT_MAX.' gramos.');
        }

        return new self((int) $digits);
    }

    public function multiply(mixed $factor): self
    {
        if (! is_int($factor) || $factor < 1) {
            throw new InvalidArgumentException('El factor debe ser un entero positivo.');
        }
        if ($this->value > intdiv(PHP_INT_MAX, $factor)) {
            throw new InvalidArgumentException('La cantidad calculada de un ingrediente supera el límite de gramos permitido.');
        }

        return new self($this->value * $factor);
    }
}
