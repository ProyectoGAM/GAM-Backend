<?php

namespace App\Modules\Inventory\Domain\Enums;

enum InventoryMovementType: string
{
    case OpeningBalance = 'opening_balance';
    case Receipt = 'receipt';
    case Issue = 'issue';
    case Loss = 'loss';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case Reservation = 'reservation';
    case Release = 'release';
    case Consumption = 'consumption';
    case Reversal = 'reversal';
}
