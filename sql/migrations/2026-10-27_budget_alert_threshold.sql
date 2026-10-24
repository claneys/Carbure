-- Budget alerts with a global threshold and thresholds per category: users.budget_alert_all
-- turns the global threshold (users.budget_threshold, all the budgets) on or off, and
-- budget_alert_threshold gives a category its own threshold, used instead of the global one.
-- The settings of before are kept: a user who watched one category (budget_alert_category)
-- gets the global threshold off and that category at his threshold; the others keep all
-- the budgets at their threshold. budget_alert_category stays, as a copy for the older
-- clients (the only category of a user without the global threshold).
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_alert_threshold'

ALTER TABLE `users`
  ADD COLUMN `budget_alert_all` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Global budget alert threshold on (all the budgets)' AFTER `budget_alert_category`;

CREATE TABLE `budget_alert_threshold` (
  `user_id` int(10) unsigned NOT NULL,
  `category` tinyint(3) unsigned NOT NULL,
  `threshold` smallint(5) unsigned NOT NULL COMMENT 'Percent of the budget of the category that alerts (50 to 200)',
  PRIMARY KEY (`user_id`, `category`),
  KEY `budget_alert_threshold_category` (`category`),
  CONSTRAINT `fk_budget_alert_threshold_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budget_alert_threshold_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `budget_alert_threshold` (`user_id`, `category`, `threshold`)
  SELECT `id`, `budget_alert_category`, `budget_threshold` FROM `users` WHERE `budget_alert_category` IS NOT NULL;

UPDATE `users` SET `budget_alert_all` = 0 WHERE `budget_alert_category` IS NOT NULL;
