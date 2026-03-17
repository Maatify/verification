<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exception;

use Maatify\Exceptions\Exception\MaatifyException;
use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;

abstract class VerificationException extends MaatifyException
{
    abstract protected function defaultErrorCode(): ErrorCodeInterface;
    abstract protected function defaultCategory(): ErrorCategoryInterface;
    abstract protected function defaultHttpStatus(): int;
}
