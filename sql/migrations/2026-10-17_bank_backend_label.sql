-- Name given to a bank connection (woob backend, e.g. bnp_2), e.g. "Compte joint",
-- shown in the portal instead of its woob name. No row: the bank name is shown.
-- applied-if: SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_backend'

CREATE TABLE IF NOT EXISTS `bank_backend` (
  `name` varchar(50) NOT NULL COMMENT 'Name of the woob backend',
  `label` varchar(100) NOT NULL COMMENT 'Name given to the bank connection',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
