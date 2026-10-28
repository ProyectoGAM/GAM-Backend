<?php

namespace App\Enums\Inventory;

enum FeedBalanceStatus: string
{
    case Unconfigured = 'sin_configurar';
    case Sufficient = 'suficiente';
    case AtMinimum = 'en_minimo';
    case BelowMinimum = 'por_debajo';

    public static function forQuantities(int $quantity, ?int $minimum): self
    {
        return match (true) {
            $minimum === null => self::Unconfigured,
            $quantity > $minimum => self::Sufficient,
            $quantity === $minimum => self::AtMinimum,
            default => self::BelowMinimum,
        };
    }

    public function requiresAttention(): bool
    {
        return $this === self::AtMinimum || $this === self::BelowMinimum;
    }
}
