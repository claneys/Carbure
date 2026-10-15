-- Budget alert threshold of a user: percent of the budget of a category whose spending
-- of the month alerts him (kind "budget" of the notifications, off by default). 100
-- for the existing users.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'budget_threshold'

ALTER TABLE `users`
  ADD COLUMN `budget_threshold` smallint(5) unsigned NOT NULL DEFAULT 100 COMMENT 'Percent of a budget whose spending of the month alerts (50 to 200)' AFTER `alert_threshold`;
