-- Suppliers are archived, never deleted: past orders keep pointing at them (the source set supplier_id to NULL on delete).
CREATE TABLE suppliers (
  id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name          VARCHAR(150) NOT NULL,
  contact_name  VARCHAR(150) NULL,
  phone         VARCHAR(40) NULL,
  email         VARCHAR(190) NULL,
  address       VARCHAR(300) NULL,
  notes         VARCHAR(1000) NULL,
  archived_at   DATETIME(3) NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_suppliers_business (business_id, archived_at, name),
  CONSTRAINT fk_suppliers_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- status: draft -> ordered -> partially_received -> received ; closed = stopped waiting for the rest; cancelled = nothing was received.
-- outlet_id is where received stock lands (and who may see the order).
CREATE TABLE purchase_orders (
  id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  supplier_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  outlet_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  order_number  VARCHAR(30) CHARACTER SET ascii NOT NULL,
  status        ENUM('draft','ordered','partially_received','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  total_cost    DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes         VARCHAR(1000) NULL,
  ordered_at    DATETIME(3) NULL,
  received_at   DATETIME(3) NULL,
  created_by    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_po_number (business_id, order_number),
  KEY idx_po_status (business_id, status, outlet_id),
  KEY idx_po_supplier (business_id, supplier_id),
  CONSTRAINT fk_po_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_outlet FOREIGN KEY (outlet_id) REFERENCES locations(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE purchase_order_items (
  id                 CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purchase_order_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_id         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  product_name       VARCHAR(200) NOT NULL,
  quantity_ordered   INT NOT NULL,
  quantity_received  INT NOT NULL DEFAULT 0,
  unit_cost          DECIMAL(15,2) NOT NULL DEFAULT 0,
  line_total         DECIMAL(15,2) NOT NULL DEFAULT 0,
  position           INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_poi_order (purchase_order_id, position),
  KEY idx_poi_product (business_id, product_id),
  CONSTRAINT fk_poi_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT,
  CONSTRAINT fk_poi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_poi_qty CHECK (quantity_ordered > 0 AND quantity_received >= 0 AND quantity_received <= quantity_ordered)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per accepted delivery. UNIQUE(business_id, client_operation_id) makes a retried delivery a no-op.
CREATE TABLE purchase_receipts (
  id                   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purchase_order_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  client_operation_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  request_hash         CHAR(64) CHARACTER SET ascii NOT NULL,
  user_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_operation (business_id, client_operation_id),
  KEY idx_receipt_order (purchase_order_id, created_at),
  CONSTRAINT fk_receipt_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE purchase_receipt_items (
  receipt_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  item_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quantity    INT NOT NULL,
  PRIMARY KEY (receipt_id, item_id),
  CONSTRAINT fk_ri_receipt FOREIGN KEY (receipt_id) REFERENCES purchase_receipts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ri_item FOREIGN KEY (item_id) REFERENCES purchase_order_items(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ri_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
