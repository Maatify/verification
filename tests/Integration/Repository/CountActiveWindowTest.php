<?php
declare(strict_types=1);

namespace Tests\Integration\Repository;

use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class CountActiveWindowTest extends DatabaseTestCase
{
    public function testCountActiveInWindowWorksCorrectly(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);

        // One old code
        $codeOld = new VerificationCode(0, IdentityTypeEnum::User, 'user1', VerificationPurposeEnum::EmailVerification, 'h1', VerificationCodeStatus::ACTIVE, 0, 3, $clock->now()->modify('+15 minutes'), $clock->now()->modify('-20 minutes'));
        $repository->store($codeOld);

        // One recent code
        $codeNew = new VerificationCode(0, IdentityTypeEnum::User, 'user1', VerificationPurposeEnum::EmailVerification, 'h2', VerificationCodeStatus::ACTIVE, 0, 3, $clock->now()->modify('+15 minutes'), $clock->now()->modify('-5 minutes'));
        $repository->store($codeNew);

        $count = $repository->countActiveInWindow(
            IdentityTypeEnum::User, 'user1', VerificationPurposeEnum::EmailVerification, $clock->now()->modify('-10 minutes')
        );

        $this->assertEquals(1, $count);
    }
}
