<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\DTO;

use Maatify\Verification\Domain\Enum\VerificationUseStatus;

readonly class VerificationUseResult
{
    public function __construct(
        public VerificationUseStatus $status,
        public ?string $message = null
    ) {
    }
}
