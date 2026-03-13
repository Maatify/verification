# Specification Compliance Audit Report: maatify/verification

## 1. Specification Coverage
The implementation covers a significant portion of the `VERIFICATION_SPEC.md` specification:

*   **Identity Model (Sections 2 & 3):** The `IdentityTypeEnum` and the database schema successfully implement the specified identity model, supporting the exact list of required types (admin, user, customer, merchant, vendor, agent, company, subaccount, partner, reseller, affiliate).
*   **OTP Generation (Section 5):** The system successfully uses `random_int()` for secure OTP generation and only stores the `sha256(code)` hash.
*   **Verification Lifecycle & Expiration (Sections 6 & 7):** Codes use the defined statuses (`ACTIVE`, `USED`, `EXPIRED`, `REVOKED`) and enforce the `expires_at` logic during validation.
*   **Attempt Protection (Section 8):** The `attempts` and `max_attempts` fields are present, and validation fails if limits are exceeded.
*   **Multi-Code Window & Cooldowns (Sections 9, 10, & 11):** `VerificationCodeGenerator` correctly implements logic for checking active codes, enforcing `resendCooldownSeconds`, revoking the oldest codes if `maxActiveCodes` is exceeded, and enforcing `maxCodesPerWindow`.
*   **Replay Protection & Revoke On Success (Sections 13 & 14):** Successful validations transition codes to `USED` and successfully revoke all other active codes for the identity and purpose.
*   **Redis Rate Limiting Architecture (Sections 16, 17, 18, 19):** `RedisRateLimiter` supports optional rate limiting, uses the `maatify:verification` prefix (configurable), follows the `{prefix}:rate:{identity_type}:{identity_id}:{purpose}` key structure, and utilizes time-blocked hash fields (`5m`, `1h`, `24h`).

## 2. Implementation Deviations

The following points detail exact locations where the code diverges from the required specification:

*   **Missing `used_at` Field (Section 4 & 12):**
    *   **Spec Requirement:** Section 4 lists `used_at` as an important field. Section 12 mandates an atomic SQL update setting `used_at = NOW()`.
    *   **Code Deviation:** The `verification_codes` database schema (in `database/verification_codes.sql`), the `VerificationCode` DTO, and the `PdoVerificationCodeRepository` implementation completely omit the `used_at` field. Only `used_ip` is present. The `markUsed` SQL query does not set a `used_at` timestamp.

*   **Race Condition in Atomic Code Generation (Section 15):**
    *   **Spec Requirement:** Generation must guarantee `active_codes <= max_active_codes` even under parallel requests. Recommended implementation: transaction or row locking.
    *   **Code Deviation:** `VerificationCodeGenerator::generate()` performs a series of distinct, non-atomic read and write operations (`countActiveInWindow`, `findAllActive`, followed by `revokeAllFor`, and finally `store`). It does not utilize database transactions or row-level locking. Under high concurrency, this race condition will allow the generation of codes exceeding `max_active_codes`.

*   **Race Condition in Attempt Protection (Section 8 & 12):**
    *   **Spec Requirement:** Validation MUST be atomic to prevent race conditions (Section 12). If `attempts >= max_attempts` then `status = EXPIRED` (Section 8).
    *   **Code Deviation:** `VerificationCodeValidator` suffers from a Time-of-Check to Time-of-Use (TOCTOU) vulnerability. The attempt incrementing logic (`incrementAttempts`) and the subsequent expiration check (`expire`) are separate database calls relying on stale in-memory state (`$code->attempts`). This violates the requirement for atomic validation and allows attackers to bypass the brute-force limit under concurrent validation attempts.

*   **Unspecified Validation Method (`validateByCode`):**
    *   **Spec Requirement:** Section 2 requires verification challenges to be identified using `identity_type`, `identity_id`, and `purpose`.
    *   **Code Deviation:** `VerificationCodeValidator` includes a `validateByCode` method that completely ignores the identity model, looking up codes globally by hash alone. This deviates significantly from the specified identity binding rules and introduces a severe security flaw (global OTP collision).

## 3. Missing Features

*   **`used_at` Tracking:** The `used_at` datetime field is entirely absent from the schema, DTO, and repository logic.
*   **Atomic Code Generation Guarantees:** There is no implementation of database transactions or row-level locking to prevent generation race conditions as explicitly recommended by Section 15.

## 4. Implementation Bugs

*   **Non-Atomic Attempt Tracking:** The logic intended to transition a code to `EXPIRED` when `attempts >= max_attempts` relies on non-atomic PHP memory state rather than atomic database updates. This directly contradicts the "Atomic Validation" requirement (Section 12) and breaks the "Security Guarantees" for brute-force protection (Section 21).
*   **Missing Expiry/Attempt Guard in `markUsed`:** The `markUsed` SQL query in `PdoVerificationCodeRepository` checks `expires_at >= :now` but fails to check `attempts < max_attempts`, which is critical for maintaining state consistency during concurrent requests.
*   **Identity Bypass via `validateByCode`:** The presence of `validateByCode` bypasses the core identity model (Section 2) entirely.

## 5. Recommended Fixes

1.  **Add `used_at` Field:**
    *   Update `verification_codes.sql` to include `used_at DATETIME DEFAULT NULL`.
    *   Update `VerificationCode` DTO to accept `?DateTimeImmutable $usedAt`.
    *   Update `PdoVerificationCodeRepository` (both `mapRowToDto` and the `markUsed` SQL query) to handle and set `used_at = :now`.
2.  **Enforce Atomic Validation (Fix Attempt Tracking Race Condition):**
    *   Rewrite `VerificationCodeValidator` and `PdoVerificationCodeRepository` to perform atomic database updates for attempts and expiration. For example, merge `incrementAttempts` and `expire` logic into a single atomic query that evaluates `attempts >= max_attempts` directly on the database side.
    *   Add `AND attempts < max_attempts` to the `WHERE` clause of the `markUsed` query.
3.  **Enforce Atomic Generation:**
    *   Wrap the operations inside `VerificationCodeGenerator::generate` within a database transaction (`$pdo->beginTransaction()` and `$pdo->commit()`), potentially utilizing `SELECT ... FOR UPDATE` row locks or an explicit application-level lock for the specific identity to guarantee `active_codes <= max_active_codes` under concurrency.
4.  **Remove `validateByCode`:**
    *   Remove `validateByCode` entirely, as it violates the identity model specification (Section 2) and breaks security guarantees.
