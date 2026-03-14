<?php

declare(strict_types=1);

namespace Maatify\Verification\Infrastructure\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use PDO;

readonly class PdoVerificationCodeRepository implements VerificationCodeRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock
    ) {
    }

    public function store(VerificationCode $code): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO verification_codes (
                identity_type, identity_id, purpose, code_hash,
                status, attempts, max_attempts, expires_at, created_at, used_at, created_ip, used_ip
            ) VALUES (
                :identity_type, :identity_id, :purpose, :code_hash,
                :status, :attempts, :max_attempts, :expires_at, :created_at, :used_at, :created_ip, :used_ip
            )
        ');

        $stmt->execute([
            'identity_type' => $code->identityType->value,
            'identity_id' => $code->identityId,
            'purpose' => $code->purpose->value,
            'code_hash' => $code->codeHash,
            'status' => $code->status->value,
            'attempts' => $code->attempts,
            'max_attempts' => $code->maxAttempts,
            'expires_at' => $code->expiresAt->format('Y-m-d H:i:s'),
            'created_at' => $code->createdAt->format('Y-m-d H:i:s'),
            'used_at' => $code->usedAt?->format('Y-m-d H:i:s'),
            'created_ip' => $code->createdIp,
            'used_ip' => $code->usedIp,
        ]);
    }

    public function findActive(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): ?VerificationCode
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM verification_codes
            WHERE identity_type = :identity_type
            AND identity_id = :identity_id
            AND purpose = :purpose
            AND status = 'active'
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id' => $identityId,
            'purpose' => $purpose->value,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || !is_array($row)) {
            return null;
        }

        return $this->mapRowToDto($row);
    }

    public function incrementAttempts(
        IdentityTypeEnum $identityType,
        string $identityId,
        VerificationPurposeEnum $purpose
    ): void {
        $stmt = $this->pdo->prepare("
            UPDATE verification_codes
            SET attempts = attempts + 1,
                status = CASE
                    WHEN attempts + 1 >= max_attempts THEN 'expired'
                    ELSE status
                END
            WHERE id = (
                SELECT id FROM (
                    SELECT id FROM verification_codes
                    WHERE identity_type = :identity_type
                      AND identity_id = :identity_id
                      AND purpose = :purpose
                      AND status = 'active'
                    ORDER BY created_at DESC
                    LIMIT 1
                ) as target_row
            )
        ");
        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id'   => $identityId,
            'purpose'       => $purpose->value,
        ]);
    }

    public function markUsed(
        IdentityTypeEnum $identityType,
        string $identityId,
        VerificationPurposeEnum $purpose,
        string $codeHash,
        ?string $usedIp = null
    ): bool {
        $stmt = $this->pdo->prepare("
            UPDATE verification_codes
            SET status = 'used', used_ip = :used_ip, used_at = :now
            WHERE identity_type = :identity_type
              AND identity_id = :identity_id
              AND purpose = :purpose
              AND code_hash = :code_hash
              AND status = 'active'
              AND expires_at >= :now
              AND attempts < max_attempts
        ");
        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id'   => $identityId,
            'purpose'       => $purpose->value,
            'code_hash'     => $codeHash,
            'used_ip'       => $usedIp,
            'now'           => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return $stmt->rowCount() > 0;
    }

    public function expire(int $codeId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE verification_codes
            SET status = 'expired'
            WHERE id = :id
        ");
        $stmt->execute(['id' => $codeId]);
    }

    public function expireAllFor(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE verification_codes
            SET status = 'expired'
            WHERE identity_type = :identity_type
            AND identity_id = :identity_id
            AND purpose = :purpose
            AND status = 'active'
        ");
        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id' => $identityId,
            'purpose' => $purpose->value,
        ]);
    }

    /**
     * @param array<int> $exceptIds
     */
    public function revokeAllFor(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, array $exceptIds = []): void
    {
        $query = "
            UPDATE verification_codes
            SET status = 'revoked'
            WHERE identity_type = :identity_type
            AND identity_id = :identity_id
            AND purpose = :purpose
            AND status = 'active'
        ";

        $params = [
            'identity_type' => $identityType->value,
            'identity_id' => $identityId,
            'purpose' => $purpose->value,
        ];

        if (!empty($exceptIds)) {
            $namedParams = [];
            foreach ($exceptIds as $i => $exceptId) {
                $paramName = ':id_' . $i;
                $namedParams[] = $paramName;
                $params[$paramName] = $exceptId;
            }
            $inQuery = implode(',', $namedParams);
            $query .= " AND id NOT IN ($inQuery)";
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
    }

    /**
     * @return VerificationCode[]
     */
    public function findAllActive(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM verification_codes
            WHERE identity_type = :identity_type
            AND identity_id = :identity_id
            AND purpose = :purpose
            AND status = 'active'
            ORDER BY created_at DESC
        ");

        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id' => $identityId,
            'purpose' => $purpose->value,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapRowToDto($row);
        }
        return $result;
    }

    public function countActiveInWindow(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, \DateTimeInterface $since): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM verification_codes
            WHERE identity_type = :identity_type
            AND identity_id = :identity_id
            AND purpose = :purpose
            AND created_at >= :since
            AND status IN ("active","used")
        ');

        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id' => $identityId,
            'purpose' => $purpose->value,
            'since' => $since->format('Y-m-d H:i:s')
        ]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToDto(array $row): VerificationCode
    {
        /** @var int $id */
        $id = $row['id'];
        /** @var string $identityType */
        $identityType = $row['identity_type'];
        /** @var string $identityId */
        $identityId = $row['identity_id'];
        /** @var string $purpose */
        $purpose = $row['purpose'];
        /** @var string $codeHash */
        $codeHash = $row['code_hash'];
        /** @var string $statusStr */
        $statusStr = $row['status'];
        /** @var int|string $attempts */
        $attempts = $row['attempts'];
        /** @var int|string $maxAttempts */
        $maxAttempts = $row['max_attempts'];
        /** @var string $expiresAt */
        $expiresAt = $row['expires_at'];
        /** @var string $createdAt */
        $createdAt = $row['created_at'];
        /** @var ?string $usedAt */
        $usedAt = $row['used_at'] ?? null;
        /** @var ?string $createdIp */
        $createdIp = $row['created_ip'] ?? null;
        /** @var ?string $usedIp */
        $usedIp = $row['used_ip'] ?? null;

        return new VerificationCode(
            (int)$id,
            IdentityTypeEnum::from($identityType),
            $identityId,
            VerificationPurposeEnum::from($purpose),
            $codeHash,
            VerificationCodeStatus::from($statusStr),
            (int)$attempts,
            (int)$maxAttempts,
            new DateTimeImmutable($expiresAt, $this->clock->getTimezone()),
            new DateTimeImmutable($createdAt, $this->clock->getTimezone()),
            $usedAt ? new DateTimeImmutable($usedAt, $this->clock->getTimezone()) : null,
            $createdIp,
            $usedIp
        );
    }

    /**
     * Locks active codes for update.
     *
     * Used to guarantee atomic generation.
     *
     * @return VerificationCode[]
     */
    public function lockActiveForUpdate(
        IdentityTypeEnum $identityType,
        string $identityId,
        VerificationPurposeEnum $purpose
    ): array {
        $stmt = $this->pdo->prepare("
        SELECT *
        FROM verification_codes
        WHERE identity_type = :identity_type
        AND identity_id = :identity_id
        AND purpose = :purpose
        AND status = 'active'
        ORDER BY created_at DESC
        FOR UPDATE
        ");

        $stmt->execute([
            'identity_type' => $identityType->value,
            'identity_id'   => $identityId,
            'purpose'       => $purpose->value,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->mapRowToDto($row);
        }

        return $result;
    }

    public function acquireGenerationLock(
        IdentityTypeEnum $identityType,
        string $identityId,
        VerificationPurposeEnum $purpose
    ): void {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Upsert the lock anchor row
        $stmtInsert = $this->pdo->prepare("
            INSERT INTO verification_generation_locks (identity_type, identity_id, purpose, locked_at)
            VALUES (:identity_type, :identity_id, :purpose, :locked_at)
            ON DUPLICATE KEY UPDATE locked_at = :locked_at
        ");

        $params = [
            'identity_type' => $identityType->value,
            'identity_id'   => $identityId,
            'purpose'       => $purpose->value,
            'locked_at'     => $now,
        ];
        $stmtInsert->execute($params);

        // Lock the row exclusively
        $stmtLock = $this->pdo->prepare("
            SELECT locked_at
            FROM verification_generation_locks
            WHERE identity_type = :identity_type
              AND identity_id = :identity_id
              AND purpose = :purpose
            FOR UPDATE
        ");
        $stmtLock->execute([
            'identity_type' => $identityType->value,
            'identity_id'   => $identityId,
            'purpose'       => $purpose->value,
        ]);
    }
}
