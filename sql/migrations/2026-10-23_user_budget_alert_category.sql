-- Budget watched by the budget alerts of a user: one category with a budget (Budget tab),
-- or NULL for all the budgets (the behaviour before this migration, kept for the
-- existing users). Back to all the budgets when the category is deleted.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'budget_alert_category'

ALTER TABLE `users`
  ADD COLUMN `budget_alert_category` tinyint(3) unsigned DEFAULT NULL COMMENT 'Category whose budget alerts (NULL: all the budgets)' AFTER `budget_threshold`,
  ADD CONSTRAINT `fk_users_budget_alert_category` FOREIGN KEY (`budget_alert_category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE SET NULL;
