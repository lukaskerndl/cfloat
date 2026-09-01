CREATE TABLE IF NOT EXISTS vip_registrace_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  full_name VARCHAR(255) NOT NULL,
  company VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  ip_address VARCHAR(64) DEFAULT NULL,
  user_agent VARCHAR(500) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
