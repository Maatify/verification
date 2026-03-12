# Security Policy

## Supported Versions

Use this section to tell people about which versions of your project are currently being supported with security updates.

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |
| < 1.0   | :x:                |

## Reporting a Vulnerability

Please report vulnerabilities by emailing support@maatify.com.

We will acknowledge receipt of your vulnerability report within 48 hours and strive to send you regular updates about our progress. If you have not received a reply to your email within 48 hours, please follow up to ensure it was received.

Please do not open a public issue on GitHub for security vulnerabilities.

## Responsible Disclosure Policy

We ask that you follow the principles of responsible disclosure:
* Give us a reasonable amount of time to fix the issue before publishing it elsewhere.
* Make a good faith effort to avoid privacy violations, destruction of data, and interruption or degradation of our service during your research.
* Do not exploit the vulnerability further than necessary to establish its existence.

## Expected Response Timelines

* **Acknowledgment:** Within 48 hours of receipt.
* **Initial Assessment:** Within 5 business days.
* **Resolution/Patch:** We aim to resolve critical issues within 14 days and high-severity issues within 30 days.

## Security Scope

The `maatify/verification` module is responsible for:
* Generating secure One-Time Passwords (OTPs).
* Safely hashing and storing those codes.
* Validating user input against the stored hashes.
* Enforcing expiration policies and attempt limits.
* Auditing creation and usage IP addresses.

**Out of Scope:**
* The actual delivery mechanism of the OTP (e.g., sending the email or SMS).
* General application authentication/authorization outside of the verification challenge.

## Security Design Highlights

This library is built with security as a primary concern. It implements several industry-standard protections:

* **Zero Plaintext Storage:** The library stores *only* hashed verification codes. The plaintext code is never stored in the database.
* **Cryptographically Secure Generation:** OTPs are generated using PHP's cryptographically secure `random_int()` function.
* **Strong Hashing:** Verification codes are hashed using the SHA-256 algorithm before storage.
* **Constant-Time Comparison:** Input validation uses `hash_equals()` to prevent timing attacks when comparing hashes.
* **Brute-Force Protection:** The library strictly tracks validation attempts. Exceeding the `max_attempts` limit immediately locks out (expires) the verification challenge.
* **Expiration Enforcement:** Codes have strict time-to-live (TTL) policies and are automatically invalidated upon expiration.
* **IP Audit Logging:** The system can track and store the IP addresses used during code generation and validation for security auditing.
* **Lifecycle Management:** Codes follow a strict state machine (`active`, `used`, `expired`, `revoked`). Requesting a new code automatically revokes or expires previous active codes for that identity and purpose.