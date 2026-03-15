<?php
declare(strict_types=1);

namespace Tests\Integration\Generator;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use RuntimeException;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class ResendCooldownTest extends DatabaseTestCase
{
    public function testSecondGenerateWithinCooldownFails(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);
        $generator = new VerificationCodeGenerator(
            $repository,
            new VerificationCodePolicyResolver(),
            $clock,
            new PdoTransactionManager($this->getPdo()),
            'test_secret'
        );

        $generator->generate(IdentityTypeEnum::User, 'user2', VerificationPurposeEnum::EmailVerification);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Please wait before requesting a new code.');

        // Attempt immediate resend (cooldown is typically 60s)
        $generator->generate(IdentityTypeEnum::User, 'user2', VerificationPurposeEnum::EmailVerification);
    }
}
