CREATE TABLE expense_categories (
  id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(300) NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_expcat_name (business_id, name),
  CONSTRAINT fk_expcat_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- expense_date is a calendar DATE in the business's own time zone (profit periods compare dates, not instants).
-- Rows are soft-deleted: removing an expense silently rewrites past profit, so it is kept, hidden, audited and restorable by an admin.
CREATE TABLE expenses (
  id                 CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outlet_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  category_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  user_id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  amount             DECIMAL(15,2) NOT NULL,
  description        VARCHAR(300) NOT NULL,
  notes              VARCHAR(1000) NULL,
  payment_method     ENUM('cash','bank_transfer','card','mobile_money','paystack','other') NOT NULL DEFAULT 'cash',
  payment_status     VARCHAR(20) NOT NULL DEFAULT 'paid',
  payment_reference  VARCHAR(100) NULL,
  receipt_image_url  VARCHAR(500) NULL,
  expense_date       DATE NOT NULL,
  deleted_at         DATETIME(3) NULL,
  deleted_by         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_expenses_date (business_id, deleted_at, expense_date),
  KEY idx_expenses_category (business_id, category_id),
  KEY idx_expenses_outlet (business_id, outlet_id, expense_date),
  CONSTRAINT fk_expenses_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_outlet FOREIGN KEY (outlet_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE RESTRICT,
  CONSTRAINT chk_expenses_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
