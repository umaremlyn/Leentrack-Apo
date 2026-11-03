-- Subscription payments. A row is created by the SERVER before the customer is sent to the payment provider; the amount, plan and cycle are
-- fixed here and are the only thing a provider confirmation is compared with (the source trusted client/webhook metadata: audit S-04).
-- Rows are RETAINED, pseudonymised, when a business is deleted (tax/accounting): business_id is set to NULL by the foreign key and user_id is
-- blanked by the purge job. Nothing else about the customer is stored here.
CREATE TABLE payments (
  id                       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  user_id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  provider                 ENUM('paystack') NOT NULL DEFAULT 'paystack',
  reference                VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  plan                     ENUM('growth','business') NOT NULL,
  cycle                    ENUM('monthly','quarterly','yearly') NOT NULL,
  currency                 CHAR(3) NOT NULL,
  amount_minor             BIGINT UNSIGNED NOT NULL,
  status                   ENUM('pending','success','failed','abandoned','refunded') NOT NULL DEFAULT 'pending',
  provider_transaction_id  VARCHAR(64) NULL,
  failure_reason           VARCHAR(100) NULL,
  period_start             DATETIME(3) NULL,
  period_end               DATETIME(3) NULL,
  paid_at                  DATETIME(3) NULL,
  settled_via              ENUM('verify','webhook') NULL,
  created_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at               DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_reference (provider, reference),
  KEY idx_payments_business (business_id, created_at),
  KEY idx_payments_retention (business_id, created_at),
  CONSTRAINT fk_payments_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE SET NULL,
  CONSTRAINT chk_payments_amount CHECK (amount_minor > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per distinct webhook delivery (hash of the raw body): a repeated delivery is acknowledged and ignored. The body itself is NOT stored
-- (it contains the customer's e-mail and card hints); only what is needed to explain what happened.
CREATE TABLE webhook_events (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider       ENUM('paystack') NOT NULL,
  payload_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_type     VARCHAR(60) NOT NULL,
  reference      VARCHAR(64) CHARACTER SET ascii NULL,
  outcome        VARCHAR(40) NOT NULL,
  received_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_webhook_payload (provider, payload_hash),
  KEY idx_webhook_reference (provider, reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
