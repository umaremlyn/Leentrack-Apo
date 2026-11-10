CREATE TABLE customer_groups (
  id                   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name                 VARCHAR(100) NOT NULL,
  description          VARCHAR(300) NULL,
  discount_percentage  DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_groups_business (business_id, name),
  CONSTRAINT fk_groups_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_groups_discount CHECK (discount_percentage >= 0 AND discount_percentage <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The production column is customer_group_id (the web app says so explicitly); the API calls it group_id.
-- loyalty_points / total_spent / visit_count / last_visit are written ONLY by the sale and loyalty services.
CREATE TABLE customers (
  id                 CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  customer_group_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  name               VARCHAR(150) NOT NULL,
  email              VARCHAR(254) NULL,
  phone              VARCHAR(32) NULL,
  address            VARCHAR(300) NULL,
  date_of_birth      DATE NULL,
  notes              VARCHAR(1000) NULL,
  loyalty_points     INT NOT NULL DEFAULT 0,
  total_spent        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  visit_count        INT NOT NULL DEFAULT 0,
  last_visit         DATETIME(3) NULL,
  archived_at        DATETIME(3) NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_customers_business (business_id, archived_at, name),
  KEY idx_customers_phone (business_id, phone),
  KEY idx_customers_email (business_id, email),
  KEY idx_customers_group (business_id, customer_group_id),
  CONSTRAINT fk_customers_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customers_group FOREIGN KEY (customer_group_id) REFERENCES customer_groups(id) ON DELETE SET NULL,
  CONSTRAINT chk_customers_counters CHECK (loyalty_points >= 0 AND total_spent >= 0 AND visit_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- No row = defaults (enabled, 1 point per 10,000 spent, 1 point worth 100), exactly like the source.
CREATE TABLE loyalty_settings (
  business_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled          TINYINT(1) NOT NULL DEFAULT 1,
  spend_per_point  DECIMAL(15,2) NOT NULL DEFAULT 10000.00,
  point_value      DECIMAL(15,2) NOT NULL DEFAULT 100.00,
  updated_by       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (business_id),
  CONSTRAINT fk_loyalty_settings_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_loyalty_rate CHECK (spend_per_point >= 1 AND spend_per_point <= 100000000),
  CONSTRAINT chk_loyalty_value CHECK (point_value >= 0 AND point_value <= 1000000 AND point_value <= spend_per_point)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Append-only. progress_after = money received that has not yet added up to a whole point.
CREATE TABLE loyalty_ledger (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  customer_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sale_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  kind             ENUM('earn','redeem') NOT NULL,
  money            DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  points           INT NOT NULL,
  progress_after   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  balance_after    INT NOT NULL,
  spend_per_point  DECIMAL(15,2) NULL,
  point_value      DECIMAL(15,2) NULL,
  note             VARCHAR(200) NULL,
  created_by       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_ledger_customer (customer_id, id),
  KEY idx_ledger_business (business_id, created_at),
  KEY idx_ledger_sale (sale_id),
  CONSTRAINT fk_ledger_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ledger_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
