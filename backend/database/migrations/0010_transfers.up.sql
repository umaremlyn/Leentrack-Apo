-- pending -> in_transit (source debited) -> completed (destination credited by what arrived) ; cancelled (stock returned if it had shipped).
-- Stock is conserved: shipped = received + variance. A variance is never dropped; it stays on the item row and in the audit log.
CREATE TABLE transfers (
  id                     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transfer_number        VARCHAR(30) CHARACTER SET ascii NOT NULL,
  from_location_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  to_location_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status                 ENUM('pending','in_transit','completed','cancelled') NOT NULL DEFAULT 'pending',
  notes                  VARCHAR(1000) NULL,
  created_by             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  shipped_at             DATETIME(3) NULL,
  received_at            DATETIME(3) NULL,
  cancelled_at           DATETIME(3) NULL,
  dispatch_operation_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  dispatch_hash          CHAR(64) CHARACTER SET ascii NULL,
  receive_operation_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  receive_hash           CHAR(64) CHARACTER SET ascii NULL,
  created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfer_number (business_id, transfer_number),
  UNIQUE KEY uq_transfer_dispatch_op (business_id, dispatch_operation_id),
  UNIQUE KEY uq_transfer_receive_op (business_id, receive_operation_id),
  KEY idx_transfer_status (business_id, status),
  CONSTRAINT fk_transfer_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_transfer_from FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_transfer_to FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT chk_transfer_distinct CHECK (from_location_id <> to_location_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE transfer_items (
  id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  transfer_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_name        VARCHAR(200) NOT NULL,
  quantity_requested  INT NOT NULL,
  quantity_shipped    INT NULL,
  quantity_received   INT NULL,
  position            INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transfer_product (transfer_id, product_id),
  KEY idx_ti_product (business_id, product_id),
  CONSTRAINT fk_ti_transfer FOREIGN KEY (transfer_id) REFERENCES transfers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ti_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ti_qty CHECK (quantity_requested > 0 AND (quantity_shipped IS NULL OR (quantity_shipped >= 0 AND quantity_shipped <= quantity_requested))
    AND (quantity_received IS NULL OR (quantity_received >= 0 AND quantity_received <= COALESCE(quantity_shipped, 0))))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
