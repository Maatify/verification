-- ==========================================================
-- Maatify Verification
-- Database Schema
-- Version: 1.1.0
-- Package: maatify/verification
-- ----------------------------------------------------------
-- Table: verification_generation_locks
--
-- Acts as a persistent mutex anchor for safe concurrent code generation.
-- ==========================================================

CREATE TABLE IF NOT EXISTS `verification_generation_locks` (
    `identity_type` VARCHAR(50) NOT NULL,
    `identity_id` VARCHAR(128) NOT NULL,
    `purpose` VARCHAR(64) NOT NULL,
    `locked_at` DATETIME NOT NULL,
    PRIMARY KEY (`identity_type`, `identity_id`, `purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
