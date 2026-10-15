-- Budget alerts sent: one per user, category and month, alerted again only when the
-- budget of the category is raised above the amount alerted and reached again.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_alert'

CREATE TABLE IF NOT EXISTS `budget_alert` (
  `user_id` int(10) unsigned NOT NULL,
  `category` tinyint(3) unsigned NOT NULL,
  `month` date NOT NULL COMMENT 'First day of the month',
  `budget` decimal(10,2) NOT NULL COMMENT 'Budget of the month when alerted',
  `spent` decimal(10,2) NOT NULL COMMENT 'Spending of the month when alerted',
  `percent` smallint(5) unsigned NOT NULL COMMENT 'Spending in percent of the budget',
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`, `category`, `month`),
  KEY `budget_alert_category` (`category`),
  CONSTRAINT `fk_budget_alert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budget_alert_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
