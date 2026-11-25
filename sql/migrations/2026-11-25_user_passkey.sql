-- Native passkey (WebAuthn) login: a discoverable credential with user verification
-- required, so it also stands for the second factor. Attestation `none`, ES256/RS256.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_passkey'

CREATE TABLE IF NOT EXISTS `user_passkey` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `credential_id` varbinary(1023) NOT NULL,
  `credential_hash` char(64) NOT NULL COMMENT 'SHA-256 of credential_id (lookup, and a unique key shorter than the index limit)',
  `public_key` text NOT NULL COMMENT 'PEM SubjectPublicKeyInfo',
  `alg` smallint(6) NOT NULL COMMENT 'COSE algorithm: -7 (ES256) or -257 (RS256)',
  `sign_count` int(10) unsigned NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `transports` varchar(100) NOT NULL DEFAULT '',
  `backed_up` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `credential_hash` (`credential_hash`),
  KEY `fk_user_passkey_user` (`user_id`),
  CONSTRAINT `fk_user_passkey_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `passkey_used_challenge` (
  `challenge_hash` char(64) NOT NULL COMMENT 'SHA-256 of a consumed registration/login challenge (replay)',
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`challenge_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
