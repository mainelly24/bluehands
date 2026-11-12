CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(80)  NOT NULL,
  phone          VARCHAR(20)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('worker','employer') NOT NULL,
  trade          VARCHAR(60)  NULL,
  area           VARCHAR(60)  NULL,
  category       ENUM('cert','exp') NULL,
  detail         VARCHAR(80)  NULL,
  video_path     VARCHAR(80)  NULL,
  balance        BIGINT       NOT NULL DEFAULT 0,
  bio_token_hash CHAR(64)     NULL,
  failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_phone (phone),
  KEY idx_role (role),
  CONSTRAINT chk_balance CHECK (balance >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  employer_id INT UNSIGNED NOT NULL,
  worker_id   INT UNSIGNED NOT NULL,
  title       VARCHAR(120) NOT NULL,
  amount      BIGINT       NOT NULL,
  status      ENUM('held','paid','refunded') NOT NULL DEFAULT 'held',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  settled_at  DATETIME     NULL,
  KEY idx_employer (employer_id),
  KEY idx_worker (worker_id),
  CONSTRAINT fk_job_employer FOREIGN KEY (employer_id) REFERENCES users(id),
  CONSTRAINT fk_job_worker   FOREIGN KEY (worker_id)   REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tx (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  text       VARCHAR(200) NOT NULL,
  amount     BIGINT       NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id, id),
  CONSTRAINT fk_tx_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vouches (
  worker_id  INT UNSIGNED NOT NULL,
  voucher_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (worker_id, voucher_id),
  CONSTRAINT fk_vouch_worker  FOREIGN KEY (worker_id)  REFERENCES users(id),
  CONSTRAINT fk_vouch_voucher FOREIGN KEY (voucher_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;