# Maatify Verification

# v1.0 Final Implementation Specification

This document defines the **final implementation specification** for the `maatify/verification` module.

The purpose of this specification is to provide a **stable and production-ready implementation target**.

All implementations **MUST follow this document**.

---

# 1. Scope

The verification module is responsible for:

* OTP generation
* OTP secure storage
* OTP validation
* verification lifecycle management
* brute force protection
* resend protection
* verification replay protection

The module is **NOT responsible** for:

* SMS delivery
* Email delivery
* push notifications
* account lookup
* user existence checks
* captcha
* IP rate limiting
* bot detection

These responsibilities belong to the **host application**.

---

# 2. Identity Model

Verification challenges are uniquely identified using:

```
identity_type
identity_id
purpose
```

Example:

```
identity_type = user
identity_id   = 154
purpose       = login
```

This triple uniquely identifies a **verification challenge scope**.

---

# 3. Supported Identity Types

The system supports the following identity types:

```
admin
user
customer
merchant
vendor
agent
company
subaccount
partner
reseller
affiliate
```

These values **must exist in both**:

* the database schema
* the `IdentityTypeEnum`

---

# 4. Database Schema

Table name:

```
verification_codes
```

Required columns:

```
id
identity_type
identity_id
purpose
code_hash
status
attempts
max_attempts
expires_at
created_at
used_at
created_ip
used_ip
```

### Column definitions

| column        | description                  |
| ------------- | ---------------------------- |
| id            | primary key                  |
| identity_type | identity category            |
| identity_id   | identity reference           |
| purpose       | verification purpose         |
| code_hash     | SHA256 hash of OTP           |
| status        | verification lifecycle state |
| attempts      | failed attempts counter      |
| max_attempts  | allowed attempts             |
| expires_at    | expiration timestamp         |
| created_at    | creation timestamp           |
| used_at       | verification timestamp       |
| created_ip    | optional request IP          |
| used_ip       | optional verification IP     |

---

# 5. Required Database Indexes

The following indexes **must exist**:

```
INDEX idx_identity_scope (identity_type, identity_id, purpose)

INDEX idx_code_hash (code_hash)

INDEX idx_created_at (created_at)
```

These indexes are required for:

* validation lookup
* generation window checks
* replay protection

---

# 6. OTP Generation

Verification codes **must be generated using**:

```
random_int()
```

Example implementation:

```
random_int(100000, 999999)
```

The OTP length **should be 6 digits by default**.

---

# 7. OTP Storage

The plaintext code **must never be stored**.

Instead the system must store:

```
sha256(code)
```

Example:

```
hash('sha256', $code)
```

---

# 8. Verification Lifecycle

Each verification code must be in one of the following states:

```
ACTIVE
USED
EXPIRED
REVOKED
```

Definitions:

ACTIVE
Code is valid and can be verified.

USED
Code was successfully verified.

EXPIRED
Code expired or exceeded attempt limit.

REVOKED
Code was invalidated due to a newer code or successful verification.

---

# 9. Expiration

Each verification code has a TTL.

Field:

```
expires_at
```

Validation must fail if:

```
current_time > expires_at
```

Expired codes must transition to:

```
status = EXPIRED
```

---

# 10. Attempt Protection

Each verification code contains:

```
attempts
max_attempts
```

Each failed validation increments:

```
attempts++
```

If:

```
attempts >= max_attempts
```

Then:

```
status = EXPIRED
```

---

# 11. Multi-Code Window

Due to SMS or Email delays, multiple active codes may exist.

Configuration:

```
max_active_codes = 3
```

Per:

```
identity_type
identity_id
purpose
```

If active codes exceed this limit:

The **oldest codes must be revoked**.

---

# 12. Generation Cooldown

To prevent resend abuse:

```
resend_cooldown
```

Example:

```
60 seconds
```

If the last code was created within the cooldown period:

Generation must be rejected.

---

# 13. Generation Window Limit

To prevent OTP storms and SMS abuse:

Configuration example:

```
max_codes_per_window = 5
generation_window = 15 minutes
```

If the number of generated codes within the window exceeds the limit:

Generation must be rejected.

---

# 14. Atomic Code Generation

Code generation **must be atomic**.

Implementations must guarantee:

```
active_codes <= max_active_codes
```

Even under concurrent requests.

Recommended implementation:

```
transaction
SELECT ... FOR UPDATE
revoke oldest
insert new code
```

---

# 15. Atomic Validation

Verification must be atomic.

Example SQL pattern:

```
UPDATE verification_codes
SET status = 'used',
    used_at = NOW()
WHERE id = ?
AND status = 'active'
```

If:

```
affected_rows = 1
```

Validation succeeded.

If:

```
affected_rows = 0
```

Validation failed.

---

# 16. Replay Protection

After successful verification:

```
status = USED
```

The same code must **never be accepted again**.

---

# 17. Revoke On Success

After successful verification:

All other active codes for the same challenge must be revoked.

Scope:

```
identity_type
identity_id
purpose
```

---

# 18. Optional Redis Rate Limiting

The module supports **optional Redis-based rate limiting**.

This is implemented through:

```
VerificationRateLimiterInterface
```

If Redis is unavailable:

```
NullRateLimiter
```

must be used.

---

# 19. Redis Namespace

All Redis keys must use a configurable prefix.

Default:

```
maatify:verification
```

---

# 20. Redis Key Structure

Redis keys follow:

```
{prefix}:rate:{identity_type}:{identity_id}:{purpose}
```

Example:

```
maatify:verification:rate:user:154:login
```

---

# 21. Redis Counter Windows

Rate limits are tracked across three windows:

```
5 minutes
1 hour
24 hours
```

---

# 22. Redis Counter Implementation

Counters are stored inside a Redis Hash.

Each window is stored using **time-block based fields**.

Example:

```
5m:2938472
1h:489372
24h:98123
```

Where the suffix represents a **time block identifier**.

This approach ensures:

* counters automatically rotate per window
* no cron jobs are required
* historical counters expire naturally

---

# 23. Example Rate Limits

Recommended limits:

```
3 codes / 5 minutes
10 codes / hour
20 codes / 24 hours
```

Applications may override these values.

---

# 24. Code-Only Validation

The module may expose:

```
validateByCode(string code)
```

This supports flows where the verification challenge is resolved using the code alone.

Examples:

* magic code login
* manual verification
* external verification gateways

However the **primary verification model remains identity-bound verification**.

---

# 25. Security Guarantees

The module guarantees:

* secure OTP generation
* hashed storage
* brute force protection
* replay protection
* resend abuse protection
* race condition protection
* verification lifecycle safety

---

# 26. Cleanup Strategy

Old verification codes should be periodically removed.

Example maintenance task:

```
DELETE FROM verification_codes
WHERE created_at < NOW() - INTERVAL 30 DAY
```

---

# 27. Framework Independence

The module must remain framework-agnostic.

Supported environments include:

```
Slim
Laravel
Symfony
Native PHP
Microservices
Serverless environments
```

---

# 28. Out of Scope

The module intentionally does not implement:

```
SMS delivery
Email sending
captcha
IP rate limiting
user lookup
account validation
provider abuse protection
```

These concerns belong to the **host system**.

---

# End of Specification
