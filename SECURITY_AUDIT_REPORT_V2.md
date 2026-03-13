# SECURITY AUDIT REPORT V2: maatify/verification

## 1. Patch Summary

The following security hardening patches were applied to the `maatify/verification` module:

1.  **Redis Rate Limiter Failure Safety**: The `RedisRateLimiter` was updated to wrap Redis multi/exec transaction calls in a `try/catch` block. It now logs the exception and "fails open" (allows generation to proceed) when the Redis server is unavailable, mitigating the DoS risk.
2.  **SQL Expiry Guard**: The `markUsed` repository method now natively enforces `expires_at >= :now` directly inside the SQL `UPDATE` statement, ensuring that an OTP cannot be marked as used if it has just expired between the PHP check and the database operation.
3.  **Attempt Counter Robustness**: The `incrementAttempts` repository method was verified. It natively executes `UPDATE verification_codes SET attempts = attempts + 1 WHERE id = :id`, which is immune to lost updates under concurrency.
4.  **Validator Constant-Time Consistency**: The `validate` and `validateByCode` methods were heavily refactored. Regardless of whether a code is not found, expired, locked, or valid but incorrect, exactly one `hash('sha256')` execution and exactly one `hash_equals()` execution are performed.

## 2. Verification of Fixes

-   **Redis DoS**: Mitigated. The application no longer crashes when Redis throws a `RedisException`.
-   **Expiry Race Condition**: Mitigated. `markUsed` checks expiration natively in the database, avoiding the TOCTOU gap.
-   **Concurrent Lockouts**: Partially mitigated. The counter increments safely, but the local `$code->attempts` evaluation in PHP is still vulnerable to TOCTOU. (See new findings).
-   **Timing Attacks**: Mitigated. The code paths now successfully balance the hash computations ensuring a near-identical execution footprint for valid vs invalid/expired/not found scenarios.

## 3. Newly Discovered Vulnerabilities

During the second aggressive security audit, focusing on identity binding, concurrency, replay edge cases, and lookup correctness, the following remaining vulnerabilities were discovered:

### Finding 1: Concurrent Validation Bypass (TOCTOU on Max Attempts Limit) (HIGH)

**Description:**
While Patch #3 ensured that `incrementAttempts` safely increments the counter in the DB, the *evaluation* of whether a user is locked out (`$code->attempts >= $code->maxAttempts`) still happens in PHP using the initially loaded `$code` object.

**Attack Scenario:**
If an attacker sends 100 concurrent requests with different incorrect OTPs, all 100 requests will load the code from the DB with `attempts = 0`. All 100 requests will fail the hash check, call `incrementAttempts()`, and then evaluate `if ($code->attempts + 1 >= $code->maxAttempts)`. Since `$code->attempts` is still 0 in PHP memory for all 100 requests, none of them will trigger `$this->repository->expire($code->id)`. The DB counter will reach 100, bypassing the max attempts limit (e.g., 3).

### Finding 2: Replay Attack via Race Condition in validateByCode (MEDIUM)

**Description:**
The `validateByCode` method checks the status (USED, REVOKED, EXPIRED) in PHP. If an attacker submits the *correct* code concurrently multiple times, all requests will load the active code. They will all pass the PHP status checks, the PHP expiry checks, and the hash comparison. They will all then call `markUsed`. While `markUsed` safely ensures only one succeeds (returns `true`), the other concurrent requests that fail `markUsed` will return `VerificationResult::failure('Invalid code.')`. This is functionally safe from a double-usage perspective, but it leaks whether a code was actively consumed or simply invalid.

### Finding 3: Missing Identity Binding in validateByCode (CRITICAL)

**Description:**
`validateByCode` was updated for constant-time comparisons but still fundamentally validates only by SHA-256 hash.

**Attack Scenario:**
As stated in the first audit, 6-digit OTPs have extremely low entropy. `validateByCode` allows an attacker to continuously guess 6-digit codes. Because there is no identity bound to the request, any correct 6-digit code guessed globally across the entire system will succeed, validating the wrong user's session.

### Finding 4: Inconsistent Dummy Hash Matching (LOW)

**Description:**
In `validateByCode`, if the `$code` is null, `$hashToCompare` uses `$dummyHash` (`hash('sha256', '000000')`). `hashMatches = hash_equals($hashToCompare, $codeHash)`. If an attacker literally inputs `000000`, the dummy hash and input hash will match (`$hashMatches = true`). The logic handles this by using `$isValid` (`false`), preventing validation success. However, it's a structural weakness that the dummy hash can be intentionally matched.

## 4. Severity Classification

| Finding | Severity | Component |
|---|---|---|
| **1. Missing Identity Binding in validateByCode** | **CRITICAL** | `VerificationCodeValidator::validateByCode` |
| **2. Concurrent Validation Bypass (TOCTOU)** | **HIGH** | `VerificationCodeValidator` |
| **3. Replay Attack via Race Condition in validateByCode** | **MEDIUM** | `VerificationCodeValidator` |
| **4. Inconsistent Dummy Hash Matching** | **LOW** | `VerificationCodeValidator` |

## 5. Recommended Fixes

1.  **Missing Identity Binding**: `validateByCode` must either be deprecated, restricted to high-entropy string tokens, or updated to require `IdentityType` and `IdentityId`.
2.  **Concurrent Validation Bypass**: The database schema/repository must handle lockout autonomously. Instead of `$code->attempts >= $code->maxAttempts` in PHP, `incrementAttempts` should return a boolean indicating if the max was reached (e.g., using a locked transaction or checking `RETURNING attempts` in Postgres), or `markUsed` should enforce `AND attempts < max_attempts` in SQL.
3.  **Replay Attack Leak**: Ensure that concurrent requests failing `markUsed` return a generic failure indistinguishable from an incorrect code.
4.  **Dummy Hash**: Use a structurally impossible or randomly generated cryptographically secure string for the dummy hash, rather than a predictable predictable 6-digit zero string.