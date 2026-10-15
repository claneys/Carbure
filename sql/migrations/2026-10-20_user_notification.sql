-- Channels of each kind of notification chosen by each user (push to the iOS app,
-- email). No row for a kind: push on, email off (the behavior before this table).
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_notification'

CREATE TABLE IF NOT EXISTS `user_notification` (
  `user_id` int(10) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL COMMENT 'sync (synchronization result), rule (rule marked notify), expense (large expense)',
  `push` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Push to the devices of the user (iOS app)',
  `email` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Email to the address of the user',
  PRIMARY KEY (`user_id`, `kind`),
  CONSTRAINT `fk_user_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
