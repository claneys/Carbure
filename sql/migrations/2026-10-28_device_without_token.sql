-- A mobile app that sends no push token at login (push not available in its build, token
-- not received in time, notifications refused) is still listed in the devices of its user,
-- without notification: NULL token (several NULLs are allowed by the unique key).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'token' AND IS_NULLABLE = 'YES'

ALTER TABLE `devices`
  MODIFY `token` varchar(200) DEFAULT NULL COMMENT 'Push token (APNs or FCM), NULL when the app sent none';
