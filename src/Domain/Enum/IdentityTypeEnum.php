<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Enum;

enum IdentityTypeEnum: string
{
    case User = 'user';
    case Email = 'email';
}
