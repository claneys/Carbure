-- Display theme chosen by a user in the portal: "system" (follows the light/dark
-- preference of the system), "light" or "dark". "system" for the existing users.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'theme'

ALTER TABLE `users`
  ADD COLUMN `theme` varchar(10) NOT NULL DEFAULT 'system' COMMENT 'Display theme of the portal: system, light or dark' AFTER `language`;
