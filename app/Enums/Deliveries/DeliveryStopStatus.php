<?php

namespace App\Enums\Deliveries;

enum DeliveryStopStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case NotDelivered = 'not_delivered';
}
