CREATE TABLE categories (
  id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name         VARCHAR(100) NOT NULL,
  description  VARCHAR(300) NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (business_id, name),          -- utf8mb4_0900_ai_ci => case/accent-insensitive uniqueness
  CONSTRAINT fk_categories_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE products (
  id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  category_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  name            VARCHAR(200) NOT NULL,
  description     VARCHAR(1000) NULL,
  sku             VARCHAR(80) NULL,
  barcode         VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  item_type       ENUM('product','service') NOT NULL DEFAULT 'product',
  selling_price   DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  cost_price      DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  -- Business-wide total = SUM(stock_levels.quantity). Never written directly by clients; only StockService changes it.
  stock_quantity  INT NOT NULL DEFAULT 0,
  min_stock_level INT NOT NULL DEFAULT 0,
  reorder_level   INT NOT NULL DEFAULT 5,
  image_url       VARCHAR(500) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  archived_at     DATETIME(3) NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  -- Barcode must be unique among NON-archived products of a business; archiving frees it for reuse.
  barcode_live    VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (IF(archived_at IS NULL, barcode, NULL)) VIRTUAL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_barcode_live (business_id, barcode_live),
  KEY idx_products_business_active (business_id, archived_at, is_active),
  KEY idx_products_category (business_id, category_id),
  KEY idx_products_sku (business_id, sku),
  KEY idx_products_name (business_id, name),
  CONSTRAINT fk_products_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT chk_products_prices CHECK (selling_price >= 0 AND cost_price >= 0),
  CONSTRAINT chk_products_levels CHECK (min_stock_level >= 0 AND reorder_level >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Per-outlet stock. Quantity may only go negative through the offline-sync "oversold" path (Phase 4); online code refuses it.
CREATE TABLE stock_levels (
  product_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  location_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quantity     INT NOT NULL DEFAULT 0,
  updated_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (product_id, location_id),
  KEY idx_levels_business_location (business_id, location_id),
  CONSTRAINT fk_levels_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_levels_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_levels_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Append-only ledger of every stock change (who, why, how much, resulting quantity).
CREATE TABLE stock_movements (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  product_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  location_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  delta           INT NOT NULL,
  quantity_after  INT NOT NULL,
  reason          VARCHAR(30) NOT NULL,
  ref_type        VARCHAR(30) NULL,
  ref_id          VARCHAR(64) NULL,
  note            VARCHAR(300) NULL,
  actor_user_id   CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_movements_product (business_id, product_id, created_at),
  KEY idx_movements_location (business_id, location_id, created_at),
  KEY idx_movements_ref (ref_type, ref_id),
  CONSTRAINT fk_movements_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movements_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_movements_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_movements_nonzero CHECK (delta <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
