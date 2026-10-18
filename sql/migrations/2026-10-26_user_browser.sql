-- Web browsers from which each user logged in to the portal, listed in his devices: an
-- HttpOnly cookie identifies the browser (only its SHA-256 is kept). Not in `devices`: a
-- browser receives no push notification and does not skip the second factor.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_browser'

CREATE TABLE `user_browser` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token of the cookie',
  `label` varchar(200) NOT NULL DEFAULT '' COMMENT 'User-Agent of the browser',
  `created_at` datetime NOT NULL,
  `last_login` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_token` (`user_id`, `token_hash`),
  CONSTRAINT `fk_user_browser_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
