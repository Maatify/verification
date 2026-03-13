# Security Audit Report: maatify/verification

## 1. Executive Summary
This report presents the findings of a comprehensive security audit of the `maatify/verification` repository. The audit evaluates the OTP generation, validation, storage, and rate-limiting mechanisms. The overall architecture follows sound Domain-Driven Design principles, employing hashing for OTP storage and constant-time comparisons to mitigate timing attacks. However, the audit identified **two critical vulnerabilities** related to concurrency control and identity binding that undermine the security of the validation process.

## 2. Architecture Evaluation
The verification system uses a standard domain and infrastructure separation.

**Trust Boundaries:**
- Client inputs (plain codes, identity details) are treated as untrusted.
- The database is treated as a trusted storage layer.
- Redis is used as an ephemeral rate-limiting layer.

**Identity Binding:**
- `generate()` and `validate()` enforce strict identity binding by requiring `IdentityTypeEnum` and `identityId`.
- However, the `validateByCode()` function completely drops identity binding, relying solely on the entropy of the OTP.

**Lifecycle Guarantees:**
- Codes are designed to be short-lived (TTL) and have a limited number of validation attempts (`max_attempts`).
- Generation incorporates a cooldown period and limits active codes per identity.

**Attack Surfaces:**
- The OTP generation flow.
- The OTP validation flow (highly sensitive to brute-force).
- Redis availability (fail-open design).

**Security Assumptions:**
- OTP entropy (6 digits) is assumed to be sufficient *if* brute-force protections hold.
- Database state is assumed to reflect the exact constraints of validation without race conditions.

## 3. Implementation Findings

1. **TOCTOU Race Condition in Attempt Tracking (Critical):**
   In `VerificationCodeValidator::validate` and `validateByCode`, the validation flow is subject to a Time-of-Check to Time-of-Use (TOCTOU) race condition. The in-memory `$code->attempts` value is checked against `$code->maxAttempts`. On failure, the database is incremented (`incrementAttempts`), but the subsequent check for expiration uses the *stale* in-memory `$code->attempts` loaded at the beginning of the request.
   Concurrently executing requests will all read the same initial attempt count. An attacker can send thousands of concurrent validation requests; the database `attempts` counter will increment, but none of the requests will trigger the `expire()` logic during the race window because they all evaluate `0 + 1 >= maxAttempts` (False). If the correct code is guessed within this concurrent batch, it will be accepted, bypassing the brute-force limit entirely.

2. **Global Brute-Force / Collision Vulnerability in `validateByCode` (Critical):**
   The `validateByCode(string $plainCode)` method looks up a code strictly by its hash. Since codes are generated using a 6-digit numeric space (1,000,000 possibilities), the entropy is very low. This method does not bind the validation to a specific identity. An attacker can rapidly query random 6-digit codes. Instead of guessing one specific user's code, they are guessing *any* active code in the database. If there are 10,000 active codes globally, the chance of a collision on a single guess is 1%. This allows an attacker to easily hijack a random user's verification session without knowing their identity.

3. **Database Concurrency and Missing Expiry Guard on `markUsed`:**
   The `markUsed` method in `PdoVerificationCodeRepository` includes `expires_at >= :now` but does *not* include an `attempts < max_attempts` check. This exacerbates the TOCTOU vulnerability, as a concurrently validated correct guess will still successfully update the code to 'used' even if the database `attempts` counter has concurrently exceeded the limit.

4. **Redis Fail-Open Design (Low):**
   The `RedisRateLimiter` fails open if Redis is unavailable or if a transaction fails. While this ensures high availability, an attacker who can purposefully trigger a denial-of-service against the Redis instance can bypass IP/identity rate limits, falling back solely to the database's generation window limits.

## 4. Attack Scenarios

**Scenario A: Concurrent Brute-Force to Bypass Max Attempts**
- An attacker receives a 6-digit OTP prompt.
- The system enforces a limit of 3 attempts.
- The attacker scripts 500 concurrent HTTP requests with guesses from `000000` to `000499`.
- All 500 requests execute `findActive()` simultaneously, reading `attempts = 0`.
- All requests perform the validation. The incorrect ones increment the database counter but do not trigger expiration because the in-memory state remains `0`.
- The single correct guess (if within the 500) evaluates successfully and executes `markUsed()`, authenticating the attacker.

**Scenario B: Global OTP Harvesting via `validateByCode`**
- An application uses `validateByCode()` for a "magic link" or "enter code" endpoint that doesn't pre-identify the user.
- The attacker sends continuous requests to this endpoint with randomly generated 6-digit numbers.
- Because the code space is only 10^6 and validation is unauthenticated, the attacker frequently hits active codes belonging to arbitrary users, effectively bypassing authentication for random accounts.

## 5. Vulnerability Severity Classification

| Vulnerability | Component | Severity |
|---|---|---|
| TOCTOU Race Condition in Attempt Tracking | `VerificationCodeValidator` | **CRITICAL** |
| Global Brute-Force / Collision via `validateByCode` | `VerificationCodeValidator` | **CRITICAL** |
| Missing attempt guard in `markUsed` | `PdoVerificationCodeRepository` | **MEDIUM** |
| Redis Fail-Open | `RedisRateLimiter` | **LOW** (Accepted Risk) |

## 6. Recommended Fixes

1. **Fix TOCTOU Race Condition:**
   Do not rely on the in-memory attempt counter to trigger expiration. Instead, modify the database repository to handle the expiration directly within the atomic SQL update.
   *Example update:*
   ```sql
   UPDATE verification_codes
   SET attempts = attempts + 1,
       status = CASE WHEN attempts + 1 >= max_attempts THEN 'expired' ELSE status END
   WHERE id = :id AND status = 'active'
   ```

2. **Deprecate or Redesign `validateByCode`:**
   A 6-digit code without identity binding is fundamentally insecure. Remove `validateByCode` and force all validations to require an identity context (`validate($identityType, $identityId, ...)`). If a "magic code" flow is strictly required, the code entropy must be significantly increased (e.g., a 32-character high-entropy cryptographic token).

3. **Harden `markUsed`:**
   Add a strict attempt check to the `markUsed` SQL query to prevent race conditions from successfully validating a code that should have been locked out.
   ```sql
   UPDATE verification_codes
   SET status = 'used', used_ip = :used_ip
   WHERE id = :id AND status = 'active'
   AND expires_at >= :now AND attempts < max_attempts
   ```
