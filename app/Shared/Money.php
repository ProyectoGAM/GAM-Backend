<?php

namespace App\Shared;

use Brick\Math\Exception\MathException;
use Brick\Money\Exception\MoneyException;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;

final readonly class Money
{
    private function __construct(private BrickMoney $value) {}

    public static function fromDecimal(string $amount, string $currency): self
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $amount) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('El importe y la moneda deben tener un formato válido.');
        }

        try {
            return new self(BrickMoney::of($amount, $currency));
        } catch (MathException|MoneyException $exception) {
            throw new InvalidArgumentException('El importe no respeta los decimales de la moneda o la moneda no es válida.', previous: $exception);
        }
    }

    public function amount(): string
    {
        return (string) $this->value->getAmount();
    }

    public function currency(): string
    {
        return $this->value->getCurrency()->getCurrencyCode();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    /** @return array{amount: string, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount(), 'currency' => $this->currency()];
    }
}
