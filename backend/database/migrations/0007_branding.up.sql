-- Logo / contact / bank details printed on receipts and invoices. Limits copied from the source (migration 016).
CREATE TABLE business_branding (
  business_id      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  logo_data        MEDIUMTEXT CHARACTER SET ascii COLLATE ascii_bin NULL,
  phone            VARCHAR(40) NULL,
  email            VARCHAR(120) NULL,
  address          VARCHAR(200) NULL,
  payment_details  VARCHAR(300) NULL,
  footer_note      VARCHAR(160) NULL,
  updated_by       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (business_id),
  CONSTRAINT fk_branding_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT chk_branding_logo CHECK (logo_data IS NULL OR CHAR_LENGTH(logo_data) <= 200000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
