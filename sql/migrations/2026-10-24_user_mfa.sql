-- Two-factor authentication at login (authenticator app, TOTP): the authenticator of
-- each user, his recovery codes (hashed) and the browsers he trusts for 6 months
-- (token hashed). Enforced for every user by the setting mfa_required (off by default).
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_mfa'

CREATE TABLE IF NOT EXISTS `user_mfa` (
  `user_id` int(10) unsigned NOT NULL,
  `secret` varchar(64) NOT NULL COMMENT 'TOTP secret (base32), pending until enabled by a first code',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_step` bigint(20) unsigned DEFAULT NULL COMMENT 'Time step of the last code accepted (a code works once)',
  `created_at` datetime NOT NULL,
  `enabled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_mfa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfa_recovery_code` (
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL COMMENT 'SHA-256 of the recovery code (each one works once)',
  PRIMARY KEY (`user_id`, `code_hash`),
  CONSTRAINT `fk_mfa_recovery_code_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mfa_trusted_browser` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token of the cookie',
  `label` varchar(200) NOT NULL DEFAULT '' COMMENT 'User-Agent of the browser',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `fk_mfa_trusted_browser_user` (`user_id`),
  CONSTRAINT `fk_mfa_trusted_browser_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
