# SECURITY AUDIT REPORT: maatify/verification

## 1. Executive Summary

A comprehensive, aggressive security audit was conducted on the `maatify/verification` module. The audit focused on the OTP verification lifecycle, including code generation, validation, repository queries, rate limiting, and protection against common attack vectors such as brute-forcing, race conditions, and timing attacks.

Several severe vulnerabilities were discovered during the audit. The most critical issue stems from the `validateByCode` method, which allows for global, unauthenticated brute-forcing and session hijacking due to the lack of identity binding and low-entropy (6-digit) verification codes. Additionally, race conditions were identified that completely bypass the brute-force lockout mechanisms, and timing attacks were found that leak information about the existence and state of verification codes. The optional Redis rate limiter also fails to operate safely when the Redis server is unavailable, causing a denial of service in the code generation flow.

Immediate remediation of these vulnerabilities is required before the module can be considered safe for production use.

---

## 2. Vulnerability Severity Classification

| Finding | Severity | Component |
|---|---|---|
| **1. Global OTP Brute-Force & Cross-Account Session Hijacking** | **CRITICAL** | `VerificationCodeValidator::validateByCode` |
| **2. Brute-Force Protection Bypass via Race Condition** | **HIGH** | `VerificationCodeValidator::validate` |
| **3. Denial of Service on Redis Failure** | **HIGH** | `RedisRateLimiter::hit` |
| **4. Rate Limit & Cooldown Bypass via Race Condition** | **MEDIUM** | `VerificationCodeGenerator::generate` |
| **5. Timing Attacks Leaking Code Existence and State** | **MEDIUM** | `VerificationCodeValidator` |
| **6. Unsafe DB Query in markUsed (Missing Expiry Check)** | **LOW** | `PdoVerificationCodeRepository::markUsed` |

---

## 3. Security Findings & Attack Scenarios

### Finding 1: Global OTP Brute-Force & Cross-Account Session Hijacking (CRITICAL)

**Description:**
The `validateByCode` method allows validation of an OTP strictly by its SHA-256 hash, without requiring the associated `IdentityType` or `IdentityId`. Because the library strictly generates 6-digit numeric codes (`100000` to `999999`), the entropy is extremely low (1,000,000 possible combinations).

**Attack Scenario:**
An attacker can script a loop to call `validateByCode` with all 6-digit combinations. Since no identity is required, the system cannot increment a specific user's attempt counter on failed guesses. Once the attacker guesses a code that matches *any* active code in the system, the validation succeeds. The attacker will successfully hijack the verification session of whichever random user had that code active. Furthermore, due to the Birthday Paradox, if many users are generating OTPs, collisions are highly likely. The database query uses `LIMIT 1` without ordering, meaning a user entering their legitimate code might accidentally validate another user's session if they share the same 6 digits.

**Recommended Fixes:**
- Deprecate or remove `validateByCode` entirely for 6-digit OTPs. OTPs must always be validated against a known identity.
- If `validateByCode` is required for use cases like "Magic Links", the generator must be modified to issue high-entropy, cryptographically secure string tokens (e.g., 256-bit random hex strings) instead of 6-digit integers.

### Finding 2: Brute-Force Protection Bypass via Race Condition (HIGH)

**Description:**
The `validate` method implements a maximum attempts limit to prevent brute-forcing. However, the limit check is vulnerable to a Time-of-Check to Time-of-Use (TOCTOU) race condition. When a failed guess occurs, the system increments the attempts in the database but evaluates the lockout condition using the locally read `attempts` integer (`$code->attempts + 1 >= $code->maxAttempts`).

**Attack Scenario:**
If a user is allowed 3 maximum attempts, an attacker can launch 100 concurrent HTTP requests with 100 different OTP guesses. All 100 requests read the initial state (`attempts = 0`) simultaneously. All 100 requests evaluate the hashes, fail, and increment the database counter. Because the local `$code->attempts` value is `0` for all of them, the check `0 + 1 >= 3` evaluates to `false`, and none of the requests trigger the `$this->repository->expire($code->id)` mechanism during the burst. The attacker effectively bypasses the limit and can brute-force the code. If any of the concurrent guesses is correct, `markUsed` will succeed because the status is still 'active'.

**Recommended Fixes:**
- Update `incrementAttempts` to return the new attempt count directly from the database (e.g., using `RETURNING attempts` if supported, or via a locked transaction).
- Alternatively, include the attempt boundary check natively within the `markUsed` SQL query: `AND attempts < max_attempts`.

### Finding 3: Denial of Service on Redis Failure (HIGH)

**Description:**
The `RedisRateLimiter` fails to catch exceptions originating from the Redis client. If the Redis server becomes unavailable or a connection timeout occurs, a `RedisException` will propagate upward, causing the entire `generate` function to crash.

**Attack Scenario:**
An attacker could perform a volumetric DoS attack against the Redis server or exploit network partitions. Instead of "failing open" (bypassing rate limits temporarily) or "failing securely" via a handled application error, the unhandled exception produces a fatal error, completely preventing all legitimate users from generating verification codes.

**Recommended Fixes:**
- Wrap the Redis transaction (`multi`, `exec`, `hIncrBy`) in a `try/catch` block.
- Decide on a resilient fallback policy: either log the error and permit generation (fail open), or throw a controlled domain-specific exception (fail closed) without crashing the application.

### Finding 4: Rate Limit & Cooldown Bypass via Race Condition (MEDIUM)

**Description:**
In `VerificationCodeGenerator::generate`, the checks for `countActiveInWindow` and `resendCooldownSeconds` are not atomic.

**Attack Scenario:**
An attacker can send dozens of generation requests concurrently. All requests query the database and observe that the user has not exceeded their `maxCodesPerWindow` or `resendCooldownSeconds`. All requests then proceed to insert a new code simultaneously. This allows an attacker to spam the database and potentially the user's SMS/Email provider, exhausting external API credits.

**Recommended Fixes:**
- Implement atomic locking (e.g., via the Redis rate limiter or database row locks) around the generation process for a specific identity to prevent concurrent execution.

### Finding 5: Timing Attacks Leaking Code Existence and State (MEDIUM)

**Description:**
The `VerificationCodeValidator` contains multiple timing vulnerabilities due to inconsistent use of cryptographic hashing:
1. If a code is not found, the system performs **two** SHA-256 hashes (`dummyHash` and the input hash).
2. If a code is found but expired or locked out, the system performs **zero** hashes and immediately returns.
3. If a code is found and active, the system performs **one** hash.

**Attack Scenario:**
By carefully measuring the response time of the validation endpoint, an attacker can reliably deduce whether a verification code record exists for an arbitrary user and whether that code has expired or been locked.

**Recommended Fixes:**
- Unify the execution path so that exactly one `hash_equals` and one `hash` operation occurs regardless of the state. If the code is expired or locked, evaluate a dummy hash to maintain constant-time execution before returning failure.

### Finding 6: Unsafe DB Query in markUsed (LOW)

**Description:**
The `markUsed` repository method attempts to atomically prevent double-usage by enforcing `status = 'active'` in the `UPDATE` query. However, it does not verify if the code has expired natively in the query.

**Attack Scenario:**
While PHP checks the expiration time just prior to `markUsed`, a race condition exists where a code could expire precisely between the PHP check and the database `UPDATE`. If an attacker submits a valid code exactly at the millisecond of expiration, it may be marked as used despite theoretically being invalid.

**Recommended Fixes:**
- Include the expiration check in the SQL statement: `AND expires_at >= :now`.

---

## 4. Conclusion

The module exhibits strong conceptual boundaries but suffers from significant implementation flaws, especially regarding concurrency and entropy. Addressing the `validateByCode` entropy issue and the TOCTOU race conditions must be the highest priorities to ensure the library is secure for production environments.