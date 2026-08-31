<?php

namespace Tests\Unit\Lots;

use App\Modules\Lots\Domain\ValueObjects\FlockAge;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlockAgeTest extends TestCase
{
    /** @return array<string, array{string, int, int}> */
    public static function dates(): array
    {
        return [
            'inicio' => ['2026-08-01T03:00:00Z', 0, 1],
            'ultimo día semana uno' => ['2026-08-08T02:59:59Z', 6, 1],
            'inicio semana dos' => ['2026-08-08T03:00:00Z', 7, 2],
            'semana tres' => ['2026-08-15T12:00:00Z', 14, 3],
        ];
    }

    // Flujo: calcula edad y semana usando días calendario de Montevideo.
    #[DataProvider('dates')]
    public function test_age_respects_business_timezone(string $now, int $days, int $week): void
    {
        // Consulta: usa instantes fijos a ambos lados de la medianoche local.
        $age = FlockAge::on('2026-08-01', CarbonImmutable::parse($now), 'America/Montevideo');
        $this->assertSame($days, $age->days);
        $this->assertSame($week, $age->week);
    }

    // Flujo: rechaza una fecha futura sin producir una edad negativa.
    public function test_future_entry_is_rejected(): void
    {
        // Consulta: la fecha de ingreso supera el día local actual.
        $this->expectException(\InvalidArgumentException::class);
        FlockAge::on('2026-08-02', CarbonImmutable::parse('2026-08-01T12:00:00Z'), 'America/Montevideo');
    }
}
