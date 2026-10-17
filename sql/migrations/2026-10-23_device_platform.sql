-- Platform of each device (ios: APNs, android: FCM), sent to the push service with its
-- token. The devices registered before are iOS devices (the only app until then).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devices' AND COLUMN_NAME = 'platform'

ALTER TABLE `devices`
  ADD COLUMN `platform` varchar(10) NOT NULL DEFAULT 'ios' COMMENT 'ios (APNs) or android (FCM)' AFTER `token`;
