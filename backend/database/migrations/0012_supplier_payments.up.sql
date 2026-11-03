-- Money paid to a supplier against goods that have actually been received. A payment can never exceed what is still owed on its order
-- (no prepayments, no negative balances). Payments are immutable: a mistake is corrected by a counter-entry, never by editing history.
-- They are NOT expenses: buying stock is an inventory movement, and its cost reaches profit only when the goods are sold (remembered unit cost).
CREATE TABLE supplier_payments (
  id                   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  supplier_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purchase_order_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  amount               DECIMAL(15,2) NOT NULL,
  payment_method       ENUM('cash','bank_transfer','card','mobile_money','other') NOT NULL DEFAULT 'bank_transfer',
  reference            VARCHAR(100) NULL,
  notes                VARCHAR(300) NULL,
  client_operation_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash         CHAR(64) CHARACTER SET ascii NOT NULL,
  created_by           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_supplier_payment_op (business_id, client_operation_id),
  KEY idx_sp_order (purchase_order_id, created_at),
  KEY idx_sp_supplier (business_id, supplier_id, created_at),
  CONSTRAINT fk_sp_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sp_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
  CONSTRAINT chk_sp_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
