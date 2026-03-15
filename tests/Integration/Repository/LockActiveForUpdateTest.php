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

class LockActiveForUpdateTest extends DatabaseTestCase
{
    public function testSelectForUpdateLockingWorks(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);

        $code = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hash123',
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $repository->store($code);

        $activeCodes = $repository->lockActiveForUpdate(
            IdentityTypeEnum::User, 'user123', VerificationPurposeEnum::EmailVerification
        );

        $this->assertCount(1, $activeCodes);
        $this->assertEquals('hash123', $activeCodes[0]->codeHash);
    }
}
