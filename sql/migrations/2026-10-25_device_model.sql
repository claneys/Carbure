-- Model, OS version and version of the app of each device, sent by the mobile apps at
-- login (e.g. iPhone16,1, iOS 26.0, 1.2): unknown (NULL) for the devices registered before.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'app_version'

ALTER TABLE `devices`
  ADD COLUMN `model` varchar(100) DEFAULT NULL COMMENT 'Model of the device (e.g. iPhone16,1, Google Pixel 8)' AFTER `platform`,
  ADD COLUMN `os_version` varchar(50) DEFAULT NULL COMMENT 'Version of its system (e.g. iOS 26.0, Android 15)' AFTER `model`,
  ADD COLUMN `app_version` varchar(20) DEFAULT NULL COMMENT 'Version of the mobile app (e.g. 1.2)' AFTER `os_version`;

-- The devices of the Android app registered before were stored as iOS ones (the app sent no
-- platform): every token that is not an APNs one (64 hexadecimal characters) is an FCM one.
UPDATE `devices` SET `platform` = 'android'
  WHERE `platform` = 'ios' AND `token` NOT REGEXP '^[0-9a-fA-F]{64}$';
