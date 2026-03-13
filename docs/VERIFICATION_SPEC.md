# Maatify Verification
## v1.0 Implementation Specification

This document defines the final implementation specification for the `maatify/verification` module.

The purpose of this specification is to provide a **stable implementation target** for the first production release.

All implementations MUST follow this document.

---

# 1. Scope

The verification module is responsible for:

- OTP generation
- OTP secure storage
- OTP validation
- verification lifecycle management
- brute force protection

The module is NOT responsible for:

- SMS / Email delivery
- account lookup
- user existence checks
- network rate limiting
- captcha / bot detection

These responsibilities belong to the host application.

---

# 2. Identity Model

Verification challenges are identified using three fields:

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

These values must be supported both in:

- the database schema
- the `IdentityTypeEnum`

---

# 4. Database Schema

Table name:

```

verification_codes

```

Important fields:

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

---

# 5. OTP Generation

Verification codes MUST be generated using:

```

random_int()

```

The plaintext code MUST NEVER be stored.

Instead the system stores:

```

sha256(code)

```

---

# 6. Verification Lifecycle

Each verification code must be in one of the following states:

```

ACTIVE
USED
EXPIRED
REVOKED

```

Definitions:

ACTIVE
Code is valid and can be used.

USED
Code was successfully verified.

EXPIRED
Code expired or exceeded attempt limit.

REVOKED
Code was invalidated due to a newer code or successful verification.

---

# 7. Expiration

Each verification code has a TTL.

Field:

```

expires_at

```

Validation MUST fail if current time exceeds `expires_at`.

---

# 8. Attempt Protection

Each code contains:

```

attempts
max_attempts

```

Each failed validation increments attempts.

If:

```

attempts >= max_attempts

```

Then:

```

status = EXPIRED

```

---

# 9. Multi-Code Window

Due to possible SMS or Email delays, multiple active codes may exist.

```

max_active_codes = 3

```

Per:

```

identity_type + identity_id + purpose

```

If the number exceeds the limit:

The oldest active codes must be revoked.

---

# 10. Generation Cooldown

New codes cannot be generated immediately after a previous request.

Configuration:

```

resend_cooldown

```

Example:

```

60 seconds

```

If the last code was created within the cooldown period, generation must be rejected.

---

# 11. Generation Window Limit

To prevent OTP storms and SMS abuse, the system enforces a generation window.

Example configuration:

```

max_codes_per_window = 5
generation_window = 15 minutes

````

If exceeded, new generation requests must be rejected.

---

# 12. Atomic Validation

Validation MUST be atomic to prevent race conditions.

Example SQL pattern:

```sql
UPDATE verification_codes
SET status = 'used',
    used_at = NOW()
WHERE id = ?
AND status = 'active'
````

If affected rows = 1 → validation succeeded.

If affected rows = 0 → validation failed.

---

## 12.1 Code-Only Validation

The module MAY expose an additional validation method:

```
validateByCode(string plainCode)
```

This method exists to support verification flows where the verification challenge
is resolved using the code itself rather than a pre-identified identity.

Examples may include:

* magic code flows
* external verification gateways
* manual verification interfaces

However, the **primary verification model of this module remains identity-bound verification** using:

```
identity_type
identity_id
purpose
```

Applications SHOULD prefer identity-bound validation whenever the identity context is available.

Implementations MUST ensure that:

* codes remain short-lived (`expires_at`)
* attempt limits are enforced
* replay protection is enforced
* validation remains atomic

The existence of `validateByCode()` **does not change the lifecycle guarantees or security requirements defined in this specification**.

---

# 13. Replay Protection

After successful validation:

```
status = USED
```

The same code must never be accepted again.

---

# 14. Revoke On Success

After successful verification, the system must revoke all other active codes for the same challenge:

```
identity_type
identity_id
purpose
```

---

# 15. Atomic Code Generation

Generation must avoid race conditions.

Implementation must guarantee:

```
active_codes <= max_active_codes
```

Even under parallel requests.

Recommended implementation:

* transaction
* row locking
* revoke oldest codes before insertion

---

# 16. Optional Redis Rate Limiting

The module supports optional Redis-based rate limiting.

This must be implemented through:

```
VerificationRateLimiterInterface
```

If Redis is not available:

```
NullRateLimiter
```

must be used.

---

# 17. Redis Namespace

All Redis keys must use the following prefix:

```
maatify:verification
```

This prefix MUST be configurable.

---

# 18. Redis Key Structure

Redis keys must follow this structure:

```
{prefix}:rate:{identity_type}:{identity_id}:{purpose}
```

Example:

```
maatify:verification:rate:user:154:login
```

---

# 19. Redis Counters

Counters stored inside Redis Hash:

```
5m
1h
24h
```

Example:

```
5m  -> 2
1h  -> 6
24h -> 11
```

---

# 20. Example Limits

Recommended limits:

```
3 codes / 5 minutes
10 codes / hour
20 codes / 24 hours
```

---

# 21. Security Guarantees

The verification module guarantees:

* secure OTP generation
* hashed storage
* brute force protection
* replay protection
* race condition protection
* resend abuse protection

---

# 22. Out of Scope

The module does NOT implement:

```
IP rate limiting
captcha
bot detection
SMS provider protection
user lookup
```

These must be implemented by the host application.

---

# 23. Framework Independence

The module must remain framework-agnostic and compatible with:

```
Slim
Laravel
Symfony
Native PHP
Microservices
```

---

# End of Specification
