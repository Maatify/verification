# Security Audit Report

This report evaluates the security posture of the `maatify/verification` module.

## 1. Overview

The `maatify/verification` library provides a framework-agnostic component for managing One-Time Passwords (OTPs) and temporary verification codes. Its primary focus is to secure the lifecycle of verification challenges, ensuring that codes are unpredictable, securely stored, robustly validated against brute-force attacks, and accurately audited. This document serves as a high-level review of its internal defenses and potential operational risks.

## 2. Threat Model

The main threats this module defends against include:
* **Predictable Token Generation:** An attacker predicting the next verification code.
* **Database Compromise:** An attacker gaining access to the database and using stolen codes.
* **Brute-Force Attacks:** An attacker guessing a 6-digit code by submitting thousands of requests.
* **Timing Attacks:** An attacker deducing the code by measuring the response time of the validation endpoint.
* **Replay Attacks:** Reusing a code that has already been validated.
* **Concurrent Active Codes:** Exploiting multiple active codes for the same user/purpose to increase the success probability of a brute-force attack.

## 3. OTP Generation Security

* **Mechanism:** The module uses PHP's `random_int(100000, 999999)` for OTP generation.
* **Assessment:** `random_int()` utilizes the system's cryptographically secure pseudo-random number generator (CSPRNG). This makes the codes highly unpredictable and resistant to statistical analysis or pattern prediction.

## 4. Storage Security

* **Mechanism:** The module hashes the generated plaintext code using the SHA-256 algorithm before storing it in the database.
* **Assessment:** By storing only the `code_hash`, the system protects users even if the database is compromised. The plaintext code is only available momentarily in memory during the generation process so it can be transmitted to the user.

## 5. Brute-Force Protection

* **Mechanism:** The module tracks the number of validation `attempts` against `max_attempts`. If a user fails to provide the correct code, the `attempts` counter increments. When `attempts >= max_attempts`, the code is immediately expired and further validation fails.
* **Assessment:** This is a strong defense against brute-forcing short (6-digit) numerical codes. The strict expiration prevents an attacker from systematically guessing the code within its valid timeframe.

## 6. Timing Attack Protection

* **Mechanism:** When validating user input, the module hashes the provided code and compares it to the stored hash using `hash_equals()`.
* **Assessment:** `hash_equals()` is a constant-time string comparison function. It prevents an attacker from measuring the microscopic differences in response times to deduce the correct hash character by character.

## 7. Expiration Enforcement

* **Mechanism:** Each generated code is assigned an `expires_at` timestamp based on configurable TTL policies. During validation, the module strictly checks if the current time (via a `ClockInterface`) is strictly less than `expires_at`. If not, it actively marks the code as expired in the repository and returns a failure.
* **Assessment:** Ensures codes have a strictly bounded window of utility, significantly reducing the attack surface. Active expiration during a stale validation attempt keeps the database state clean.

## 8. Revocation Model

* **Mechanism:** The system maintains a state machine for codes (`active`, `used`, `expired`, `revoked`). Requesting a new verification code for a specific identity and purpose automatically expires or revokes any previously active codes for that same context.
* **Assessment:** This "invalidate-on-create" behavior ensures there is only ever *one* valid code per identity/purpose at any given time. This prevents attackers from hoarding active codes to artificially increase their brute-force success rate. The explicit `revoked` state provides a granular way to administratively invalidate a code without it being explicitly 'used' or 'expired' by time.

## 9. Database Schema Security

* **Mechanism:** The database schema (`verification_codes.sql`) enforces strict typing, including:
  * `identity_type` ENUM constraints (`admin`, `user`, `customer`) to prevent invalid identity types from being injected.
  * Appropriate indexing on lookup fields (`idx_active_lookup`, `idx_code_hash`) to prevent Denial-of-Service (DoS) via slow table scans during validation.
  * Audit fields (`created_ip`, `used_ip`) for tracking potential abuse origins.
* **Assessment:** The schema is well-designed to support the application's security requirements while maintaining referential integrity and performance.

## 10. Dependency Review

* **Mechanism:** The module relies on native PHP extensions (`ext-pdo`, `ext-json`) and minimal, trusted dependencies (`maatify/shared-common`).
* **Assessment:** The low dependency footprint minimizes the risk of introducing third-party vulnerabilities (supply chain attacks) into the core verification logic.

## 11. Known Limitations

* **Transport Security:** The module generates the plain text code, but it is the responsibility of the host application to transmit it securely (e.g., via TLS-encrypted SMTP, secure SMS gateways). If the transport layer is compromised, the code is compromised.
* **IP Spoofing:** The module logs `created_ip` and `used_ip`, but it relies on the host application to correctly resolve the client's actual IP address (e.g., handling `X-Forwarded-For` headers securely behind a proxy). Incorrect configuration by the host application could lead to easily spoofed IP audit logs.

## 12. Security Recommendations

*   **Rate Limiting:** While the module protects individual codes from brute-forcing, the host application *must* implement rate-limiting on the generation endpoint (e.g., limiting how often a specific IP or user can request a *new* code) to prevent SMS/Email pumping attacks or DoS.
*   **Secure Context Injection:** Ensure that the `ClockInterface` and IP resolution logic provided to the module are robust and cannot be tampered with by the end-user.