-- Offline sync ledger: one row per operation a device has pushed (see docs/14_SYNC_PROTOCOL.md).
-- The ledger is a cache of outcomes plus the inbox of operations that need a person; correctness never depends on it, because every
-- operation type is ALSO idempotent in its own table (sales/repayments by client_operation_id, new records by client-chosen id,
-- stock adjustments by movement reference). So a crash between "applied" and "recorded" only means the next retry is answered again.
CREATE TABLE sync_operations (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  client_operation_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  device_id            VARCHAR(64) NULL,
  user_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  type                 VARCHAR(40) CHARACTER SET ascii NOT NULL,
  status               ENUM('applied','conflict','rejected','discarded') NOT NULL,
  code                 VARCHAR(40) CHARACTER SET ascii NULL,
  message              VARCHAR(300) NULL,
  request_hash         CHAR(64) CHARACTER SET ascii NOT NULL,
  payload              JSON NULL COMMENT 'kept only while the operation still needs a person (conflict/rejected)',
  result               JSON NULL,
  client_created_at    DATETIME(3) NULL,
  occurred_at          DATETIME(3) NULL COMMENT 'client time corrected for the device clock error; what the record is dated',
  received_at          DATETIME(3) NOT NULL,
  processed_at         DATETIME(3) NOT NULL,
  resolved_by          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  resolved_at          DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync_operation (business_id, client_operation_id),
  KEY idx_sync_attention (business_id, status, received_at),
  KEY idx_sync_user (business_id, user_id, status),
  CONSTRAINT fk_sync_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
