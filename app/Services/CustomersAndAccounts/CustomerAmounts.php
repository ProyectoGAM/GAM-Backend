<?php

namespace App\Services\CustomersAndAccounts;

use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use Illuminate\Validation\ValidationException;

final class CustomerAmounts
{
    public const MAXIMUM = '9999999999999999999.999';

    public static function parse(mixed $value, bool $allowZero = false, bool $signed = false): Money
    {
        $pattern = $signed ? '/^-?\d{1,19}(?:\.\d{1,3})?$/D' : '/^\d{1,19}(?:\.\d{1,3})?$/D';
        if (! is_string($value) || ! preg_match($pattern, $value)) {
            throw ValidationException::withMessages(['amount' => 'Indicá un importe en UYU con hasta tres decimales.']);
        }
        $money = Money::of($value, 'UYU', new CustomContext(3));
        if ((! $allowZero && $money->isZero()) || (! $signed && $money->isNegative()) || $money->abs()->isGreaterThan(self::MAXIMUM)) {
            throw ValidationException::withMessages(['amount' => 'El importe debe ser positivo y estar dentro del rango permitido.']);
        }

        return $money;
    }

    public static function decimal(Money $money): string
    {
        if ($money->abs()->isGreaterThan(self::MAXIMUM)) {
            throw ValidationException::withMessages(['amount' => 'El saldo resultante supera el rango permitido.']);
        }

        return (string) $money->getAmount();
    }
}
