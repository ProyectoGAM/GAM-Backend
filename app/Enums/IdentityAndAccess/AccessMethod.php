<?php

namespace App\Enums\IdentityAndAccess;

enum AccessMethod: string
{
    case EmailPassword = 'email_password';
    case DevicePin = 'device_pin';
}
