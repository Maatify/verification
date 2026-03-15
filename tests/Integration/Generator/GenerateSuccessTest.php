<?php
declare(strict_types=1);

namespace Tests\Integration\Generator;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use PDOStatement;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class GenerateSuccessTest extends DatabaseTestCase
{
    public function testGenerateStoresCodeCorrectly(): void
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

        $result = $generator->generate(
            IdentityTypeEnum::User,
            'user1',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertNotEmpty($result->plainCode);
        $this->assertEquals(IdentityTypeEnum::User, $result->entity->identityType);
        $this->assertEquals('user1', $result->entity->identityId);
        $this->assertEquals(VerificationCodeStatus::ACTIVE, $result->entity->status);
        $this->assertEquals(0, $result->entity->attempts);

        $stmt = $this->getPdo()->query("SELECT * FROM verification_codes");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertEquals('user1', $rows[0]['identity_id']);
    }
}
