-- Per-business gap-free-on-commit counters (receipt numbers). Allocated LAST in the sale transaction to keep the lock short.
CREATE TABLE document_sequences (
  business_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind         VARCHAR(20) CHARACTER SET ascii NOT NULL,
  next_value   BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (business_id, kind),
  CONSTRAINT fk_seq_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE sales (
  id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  outlet_id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'NULL only for imported legacy sales',
  user_id             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'cashier',
  customer_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  receipt_number      VARCHAR(60) NOT NULL,
  subtotal            DECIMAL(15,2) NOT NULL,
  discount_amount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  group_discount      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  manual_discount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  points_discount     DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  points_redeemed     INT NOT NULL DEFAULT 0,
  tax_rate            DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'rate in force when the sale was made',
  tax_amount          DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  total_amount        DECIMAL(15,2) NOT NULL,
  amount_paid         DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'money received at the till (capped at total)',
  change_amount       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  payment_method      ENUM('cash','card','bank_transfer','mobile_money','other') NOT NULL DEFAULT 'cash',
  payment_method_raw  VARCHAR(40) NULL COMMENT 'verbatim legacy value kept by the importer',
  payment_status      ENUM('completed','pending','voided','refunded') NOT NULL DEFAULT 'completed',
  is_credit           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'sold on credit (even if later fully repaid)',
  notes               VARCHAR(500) NULL,
  client_operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  request_hash        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  client_created_at   DATETIME(3) NULL,
  voided_at           DATETIME(3) NULL,
  voided_by           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  void_reason         VARCHAR(200) NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_sales_receipt (business_id, receipt_number),
  UNIQUE KEY uq_sales_operation (business_id, client_operation_id),
  KEY idx_sales_business_date (business_id, created_at),
  KEY idx_sales_status_date (business_id, payment_status, created_at),
  KEY idx_sales_customer (business_id, customer_id, created_at),
  KEY idx_sales_outlet (business_id, outlet_id, created_at),
  CONSTRAINT fk_sales_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_outlet FOREIGN KEY (outlet_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sales_money CHECK (subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND amount_paid >= 0 AND change_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- product_name / item_type are SNAPSHOTS: a receipt keeps showing what was sold even if the product is renamed or archived.
CREATE TABLE sale_items (
  id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sale_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_name  VARCHAR(200) NOT NULL,
  item_type     ENUM('product','service') NOT NULL DEFAULT 'product',
  quantity      INT NOT NULL,
  unit_price    DECIMAL(15,2) NOT NULL,
  total_price   DECIMAL(15,2) NOT NULL,
  unit_cost     DECIMAL(15,2) NULL COMMENT 'cost remembered at the moment of sale (profit uses it)',
  position      INT NOT NULL DEFAULT 0,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_items_sale (sale_id, position),
  KEY idx_items_product (business_id, product_id),
  CONSTRAINT fk_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_items CHECK (quantity > 0 AND unit_price >= 0 AND total_price >= 0 AND (unit_cost IS NULL OR unit_cost >= 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Payments made AFTER a credit sale. Append-only: corrections are new rows, never edits (endpoints arrive in 3d).
CREATE TABLE debt_repayments (
  id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sale_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  customer_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  amount          DECIMAL(15,2) NOT NULL,
  payment_method  ENUM('cash','card','bank_transfer','mobile_money','other') NOT NULL DEFAULT 'cash',
  notes           VARCHAR(300) NULL,
  client_operation_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_by      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_repay_operation (business_id, client_operation_id),
  KEY idx_repay_sale (sale_id),
  KEY idx_repay_customer (business_id, customer_id),
  CONSTRAINT fk_repay_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_repay_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_repay_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT chk_repay_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- History of edits (written only by SaleService::edit; never updated).
CREATE TABLE sale_edits (
  id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  sale_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  edited_by       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  edited_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  reason          VARCHAR(200) NULL,
  old_total       DECIMAL(15,2) NOT NULL,
  new_total       DECIMAL(15,2) NOT NULL,
  paid_so_far     DECIMAL(15,2) NOT NULL,
  balance_due     DECIMAL(15,2) NOT NULL,
  refund_due      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  extra_paid      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  extra_method    VARCHAR(20) NULL,
  before_discount DECIMAL(15,2) NULL,
  after_discount  DECIMAL(15,2) NULL,
  before_items    JSON NOT NULL,
  after_items     JSON NOT NULL,
  PRIMARY KEY (id),
  KEY idx_edits_sale (sale_id, edited_at),
  KEY idx_edits_business (business_id, edited_at),
  CONSTRAINT fk_edits_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

ALTER TABLE loyalty_ledger ADD CONSTRAINT fk_ledger_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT;
