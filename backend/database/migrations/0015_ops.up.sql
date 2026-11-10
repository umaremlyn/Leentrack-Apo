-- Low-stock e-mail alert settings per business (the source kept these as free-form key/value rows in a `settings` table).
CREATE TABLE stock_alert_settings (
  business_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled       TINYINT(1) NOT NULL DEFAULT 0,
  email         VARCHAR(254) NULL,
  frequency     ENUM('daily','hourly') NOT NULL DEFAULT 'daily',
  last_sent_at  DATETIME(3) NULL,
  updated_by    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (business_id),
  KEY idx_alerts_enabled (enabled, last_sent_at),
  CONSTRAINT fk_alerts_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per scheduled-job execution (cron). Lets the operator see that the nightly jobs really run, and what they found.
CREATE TABLE job_runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job           VARCHAR(40) CHARACTER SET ascii NOT NULL,
  status        ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  started_at    DATETIME(3) NOT NULL,
  finished_at   DATETIME(3) NULL,
  summary       JSON NULL,
  PRIMARY KEY (id),
  KEY idx_job_runs (job, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
