<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Enum;

enum IdentityTypeEnum: string
{
    case Admin      = 'admin';
    case User       = 'user';
    case Customer   = 'customer';
    case Merchant   = 'merchant';
    case Vendor     = 'vendor';
    case Agent      = 'agent';
    case Company    = 'company';
    case SubAccount = 'subaccount';
    case Partner    = 'partner';
    case Reseller   = 'reseller';
    case Affiliate  = 'affiliate';
}
