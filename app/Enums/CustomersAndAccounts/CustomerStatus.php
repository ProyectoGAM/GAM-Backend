<?php

namespace App\Enums\CustomersAndAccounts;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
