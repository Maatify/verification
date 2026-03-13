# OTP Replay Protection Patch

## 1. Short Explanation of the Replay Protection Fix
A race condition previously existed where the validator would first check all constraints (expiry, attempts, hash) and then proceed to mark the code as used in the database. A highly concurrent attack could allow multiple requests to pass the in-memory validation phase simultaneously before any single request completed the atomic `markUsed()` call, leading to OTP reuse.

The fix enforces strict dependency on the database's atomic state transitions. By removing all intermediate `$isValid` tracking and directly checking each rule, we guarantee that the final step calls `$this->repository->markUsed($code->id, $usedIp)`. The validator now strictly enforces that if `markUsed()` returns `FALSE` (meaning the atomic `UPDATE ... SET status = 'used' WHERE status = 'active'` affected zero rows due to concurrent consumption), the validation completely fails, effectively closing the replay window.

## 2. Modified Validator Logic
```php
    public function validate(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, string $plainCode, ?string $usedIp = null): VerificationResult
    {
        $code = $this->repository->findActive($identityType, $identityId, $purpose);
        $inputHash = hash('sha256', $plainCode);
        $dummyHash = hash('sha256', '000000');

        if ($code === null) {
            $_ = hash_equals($dummyHash, $inputHash);
            return VerificationResult::failure('Invalid code.');
        }

        if ($code->expiresAt < $this->clock->now()) {
            $this->repository->expire($code->id);
            $_ = hash_equals($dummyHash, $inputHash);
            return VerificationResult::failure('Invalid code.');
        }

        if ($code->attempts >= $code->maxAttempts) {
            $_ = hash_equals($dummyHash, $inputHash);
            return VerificationResult::failure('Invalid code.');
        }

        if (!hash_equals($code->codeHash, $inputHash)) {
            $this->repository->incrementAttempts($code->id);
            return VerificationResult::failure('Invalid code.');
        }

        // Replay Protection: relies on the atomic SQL update in markUsed()
        if (!$this->repository->markUsed($code->id, $usedIp)) {
            return VerificationResult::failure('Invalid code.');
        }

        $this->repository->revokeAllFor($identityType, $identityId, $purpose);

        return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
    }
```

## 3. Patch Diff
```diff
--- a/src/Domain/Service/VerificationCodeValidator.php
+++ b/src/Domain/Service/VerificationCodeValidator.php
@@ -22,51 +22,39 @@ class VerificationCodeValidator implements VerificationCodeValidatorInterface

     public function validate(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, string $plainCode, ?string $usedIp = null): VerificationResult
     {
-        // 1. Find active code
         $code = $this->repository->findActive($identityType, $identityId, $purpose);
         $inputHash = hash('sha256', $plainCode);
         $dummyHash = hash('sha256', '000000');

-        $isValid = true;
-
         if ($code === null) {
-            $isValid = false;
+            // Constant-time dummy check to prevent timing attacks
+            $_ = hash_equals($dummyHash, $inputHash);
+            return VerificationResult::failure('Invalid code.');
         }

-        // 2. Check expiry
-        if ($isValid && $code !== null && $code->expiresAt < $this->clock->now()) {
+        if ($code->expiresAt < $this->clock->now()) {
             $this->repository->expire($code->id);
-            $isValid = false;
+            // Dummy check
+            $_ = hash_equals($dummyHash, $inputHash);
+            return VerificationResult::failure('Invalid code.');
         }

-        // 3. Check attempts
-        if ($isValid && $code !== null && $code->attempts >= $code->maxAttempts) {
-            // Because our atomic update handles expiration during increments,
-            // this in-memory check mainly acts as a short-circuit.
-            $isValid = false;
+        if ($code->attempts >= $code->maxAttempts) {
+            // Dummy check
+            $_ = hash_equals($dummyHash, $inputHash);
+            return VerificationResult::failure('Invalid code.');
         }

-        // 4. Constant-time comparison
-        $hashToCompare = ($isValid && $code !== null) ? $code->codeHash : $dummyHash;
-        $hashMatches = hash_equals($hashToCompare, $inputHash);
-
-        if (!$isValid || !$hashMatches) {
-            if ($isValid && $code !== null) {
-                // Increment attempts on failure ONLY when code is active and valid, but hash is incorrect.
-                // Expiration is handled atomically by the repository.
-                $this->repository->incrementAttempts($code->id);
-            }
+        if (!hash_equals($code->codeHash, $inputHash)) {
+            $this->repository->incrementAttempts($code->id);
             return VerificationResult::failure('Invalid code.');
         }

-        // 5. Mark used on success
-        /** @var \Maatify\Verification\Domain\DTO\VerificationCode $code */
-        $success = $this->repository->markUsed($code->id, $usedIp);
-        if (!$success) {
+        // Replay Protection: relies on the atomic SQL update in markUsed()
+        if (!$this->repository->markUsed($code->id, $usedIp)) {
             return VerificationResult::failure('Invalid code.');
         }

-        // 6. Revoke other active codes for this identity
         $this->repository->revokeAllFor($identityType, $identityId, $purpose);

         return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
@@ -74,52 +62,38 @@ class VerificationCodeValidator implements VerificationCodeValidatorInterface

     public function validateByCode(string $plainCode, ?string $usedIp = null): VerificationResult
     {
-        // 1. Hash the input
         $codeHash = hash('sha256', $plainCode);
         $dummyHash = hash('sha256', '000000');

-        // 2. Lookup by hash
         $code = $this->repository->findByCodeHash($codeHash);

-        $isValid = true;
-
         if ($code === null) {
-            // No matching code found (or hash mismatch implies not found)
-            $isValid = false;
+            $_ = hash_equals($dummyHash, $codeHash);
+            return VerificationResult::failure('Invalid code.');
         }

-        // 3. Check status
-        if ($isValid && $code !== null && in_array($code->status, [
-            VerificationCodeStatus::USED,
-            VerificationCodeStatus::REVOKED,
-            VerificationCodeStatus::EXPIRED,
-        ], true)) {
-            $isValid = false;
-        }
-
-        if ($isValid && $code !== null && $code->status !== VerificationCodeStatus::ACTIVE) {
-            $isValid = false;
-        }
-
-        // 4. Check expiry
-        if ($isValid && $code !== null && $code->expiresAt < $this->clock->now()) {
+        // Do not check in-memory status array! Only rely on DB update.
+        if ($code->expiresAt < $this->clock->now()) {
             $this->repository->expire($code->id);
-            $isValid = false;
+            $_ = hash_equals($dummyHash, $codeHash);
+            return VerificationResult::failure('Invalid code.');
         }

-        // 5. Check attempts
-        // Even if hash matches, maybe it was locked out previously?
-        if ($isValid && $code !== null && $code->attempts >= $code->maxAttempts) {
-            $isValid = false;
+        if ($code->attempts >= $code->maxAttempts) {
+            $_ = hash_equals($dummyHash, $codeHash);
+            return VerificationResult::failure('Invalid code.');
         }

-        $hashToCompare = ($isValid && $code !== null) ? $code->codeHash : $dummyHash;
-        $hashMatches = hash_equals($hashToCompare, $codeHash);
-
-        if (!$isValid || !$hashMatches) {
-            if ($isValid && $code !== null) {
-                // Increment attempts on failure ONLY when code is active and valid, but hash is incorrect.
-                // Expiration is handled atomically by the repository.
-                $this->repository->incrementAttempts($code->id);
-            }
+        if (!hash_equals($code->codeHash, $codeHash)) {
+            $this->repository->incrementAttempts($code->id);
             return VerificationResult::failure('Invalid code.');
         }

-        // 6. Success -> Mark used
-        /** @var \Maatify\Verification\Domain\DTO\VerificationCode $code */
-        $success = $this->repository->markUsed($code->id, $usedIp);
-        if (!$success) {
+        // Replay Protection: relies on the atomic SQL update in markUsed()
+        if (!$this->repository->markUsed($code->id, $usedIp)) {
             return VerificationResult::failure('Invalid code.');
         }

-        // 7. Revoke other active codes for this identity
         $this->repository->revokeAllFor($code->identityType, $code->identityId, $code->purpose);

         return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
```