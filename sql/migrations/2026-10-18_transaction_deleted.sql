-- Transactions deleted by a user (by UUID): a synchronization or an import of a file
-- skips them, so that a deleted transaction does not come back.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction_deleted'

CREATE TABLE IF NOT EXISTS `bank_transaction_deleted` (
  `uuid` varchar(32) NOT NULL COMMENT 'UUID of the deleted transaction',
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Date of the deletion',
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'User who deleted it',
  PRIMARY KEY (`uuid`),
  KEY `fk_transaction_deleted_user` (`user_id`),
  CONSTRAINT `fk_transaction_deleted_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
