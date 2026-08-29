<?php

namespace Tests\Unit\Inventory;

use App\Modules\Inventory\Domain\ValueObjects\InventoryQuantity;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InventoryQuantityTest extends TestCase
{
    public function test_formats_decimal_values_with_six_places(): void
    {
        $quantity = InventoryQuantity::from('12.5', BaseUnit::Kilogram, false);

        $this->assertSame('12.500000', $quantity->toString());
    }

    public function test_rejects_more_than_six_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hasta seis decimales');

        InventoryQuantity::from('1.1234567', BaseUnit::Kilogram, false);
    }

    public function test_rejects_fractional_indivisible_units(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no permite fracciones');

        InventoryQuantity::from('1.5', BaseUnit::Unit, false);
    }

    public function test_addition_keeps_exact_decimal_precision(): void
    {
        $one = InventoryQuantity::from('0.1', BaseUnit::Kilogram);
        $two = InventoryQuantity::from('0.2', BaseUnit::Kilogram);

        $this->assertSame('0.300000', $one->plus($two)->toString());
    }
}
