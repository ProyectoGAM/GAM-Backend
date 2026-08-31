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
    case Reversal = 'reversal';
}
