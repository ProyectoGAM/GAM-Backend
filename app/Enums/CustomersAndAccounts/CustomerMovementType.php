<?php

namespace App\Enums\CustomersAndAccounts;

enum CustomerMovementType: string
{
    case SaleCharge = 'sale_charge';
    case Payment = 'payment';
    case AdjustmentIncrease = 'adjustment_increase';
    case AdjustmentDecrease = 'adjustment_decrease';
    case Reversal = 'reversal';
}
