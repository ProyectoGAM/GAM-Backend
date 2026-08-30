<?php

namespace Tests\Unit\Shared;

use App\Shared\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    // Flujo: normaliza importes exactos según la escala de cada moneda.
    #[DataProvider('validAmounts')]
    public function test_preserves_exact_amount(string $amount, string $currency, string $expected): void
    {
        // Acción: construye el valor monetario sin pasar por float.
        $money = Money::fromDecimal($amount, $currency);

        // Verificación: conserva moneda, escala y signo.
        $this->assertSame(['amount' => $expected, 'currency' => $currency], $money->toArray());
        $this->assertSame(str_starts_with($amount, '-'), $money->isNegative());
    }

    /** @return array<string, array{string, string, string}> */
    public static function validAmounts(): array
    {
        return [
            'cero' => ['0', 'UYU', '0.00'],
            'precisión alta' => ['999999999999999.99', 'UYU', '999999999999999.99'],
            'sin fracción' => ['123', 'JPY', '123'],
            'tres decimales' => ['1.234', 'KWD', '1.234'],
            'cuatro decimales' => ['1.2345', 'CLF', '1.2345'],
            'importe firmado reutilizable' => ['-1.23', 'USD', '-1.23'],
        ];
    }

    // Flujo: rechaza redondeos silenciosos, notación no canónica y monedas inexistentes.
    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_money(string $amount, string $currency): void
    {
        // Preparación: espera un error explícito del valor monetario.
        $this->expectException(InvalidArgumentException::class);

        // Acción: intenta normalizar datos inválidos.
        Money::fromDecimal($amount, $currency);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidAmounts(): array
    {
        return [
            'redondeo' => ['1.001', 'UYU'],
            'moneda inexistente' => ['1', 'ZZZ'],
            'notación científica' => ['1e3', 'USD'],
            'separador decimal' => ['1,23', 'UYU'],
            'código minúsculo' => ['1', 'uyu'],
        ];
    }
}
