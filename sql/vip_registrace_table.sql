-- /www/sql/vip_registrace_table.sql

CREATE TABLE IF NOT EXISTS `vip_registrace_requests` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `status` ENUM('NEW','PROCESSED') NOT NULL DEFAULT 'NEW',
  `full_name` VARCHAR(255) NOT NULL,
  `company_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(64) NULL,
  `user_agent` VARCHAR(255) NULL,
  `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
  `email_sent_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status` (`status`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
