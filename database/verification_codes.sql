-- ==========================================================
-- Maatify Verification
-- Database Schema
-- Version: 1.0.0
-- Package: maatify/verification
-- ----------------------------------------------------------
-- Table: verification_codes
--
-- Stores one-time verification codes used for:
--  - Email verification
--  - Telegram linking
--  - Authentication challenges
--
-- Security Model:
--  - Only hashed codes are stored
--  - Attempts are tracked
--  - Codes expire automatically
--  - Only one active code per identity/purpose
-- ==========================================================

CREATE TABLE IF NOT EXISTS `verification_codes` (
                                                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

                                                    `identity_type` ENUM('admin','user','customer','merchant','vendor','agent','company','subaccount','partner','reseller','affiliate') NOT NULL,
                                                    `identity_id` VARCHAR(128) NOT NULL,

                                                    `purpose` VARCHAR(64) NOT NULL,

                                                    `code_hash` VARCHAR(64) NOT NULL,

                                                    `status` ENUM('active','used','expired','revoked') NOT NULL DEFAULT 'active',

                                                    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                                                    `max_attempts` INT UNSIGNED NOT NULL,

                                                    `expires_at` DATETIME NOT NULL,
                                                    `created_at` DATETIME NOT NULL,

                                                    `created_ip` VARCHAR(45) DEFAULT NULL,
                                                    `used_ip` VARCHAR(45) DEFAULT NULL,

                                                    PRIMARY KEY (`id`),

                                                    KEY `idx_active_lookup` (`identity_type`,`identity_id`,`purpose`,`status`),
                                                    KEY `idx_lookup_window` (`identity_type`,`identity_id`,`purpose`,`created_at`),
                                                    KEY `idx_status_expiry` (`status`,`expires_at`),
                                                    KEY `idx_code_hash` (`code_hash`),
                                                    KEY `idx_lookup_hash` (`code_hash`,`status`)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;