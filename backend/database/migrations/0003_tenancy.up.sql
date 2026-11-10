CREATE TABLE businesses (
  id                         CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name                       VARCHAR(150) NOT NULL,
  business_type              VARCHAR(60) NULL,
  currency                   CHAR(3) NOT NULL DEFAULT 'NGN',
  tax_rate                   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  owner_user_id              CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  -- Billing columns: written ONLY by the billing service / platform admin, never by tenant endpoints.
  plan                       ENUM('free','growth','business') NOT NULL DEFAULT 'free',
  plan_status                ENUM('active','cancelled','suspended','expired') NOT NULL DEFAULT 'active',
  plan_expiry                DATETIME(3) NULL,
  paystack_customer_id       VARCHAR(80) NULL,
  paystack_subscription_code VARCHAR(80) NULL,
  created_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at                 DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at                 DATETIME(3) NULL,
  PRIMARY KEY (id),
  KEY idx_businesses_owner (owner_user_id),
  KEY idx_businesses_plan_expiry (plan, plan_expiry),
  CONSTRAINT fk_businesses_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_businesses_tax CHECK (tax_rate >= 0 AND tax_rate <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE locations (
  id             CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name           VARCHAR(150) NOT NULL,
  location_type  ENUM('store','warehouse','kiosk','office') NOT NULL DEFAULT 'store',
  address        VARCHAR(300) NULL,
  city           VARCHAR(100) NULL,
  state          VARCHAR(100) NULL,
  postal_code    VARCHAR(20) NULL,
  country        VARCHAR(100) NULL DEFAULT 'Nigeria',
  phone          VARCHAR(40) NULL,
  email          VARCHAR(150) NULL,
  manager_name   VARCHAR(150) NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  is_default     TINYINT(1) NOT NULL DEFAULT 0,
  timezone       VARCHAR(64) NOT NULL DEFAULT 'Africa/Lagos',
  business_hours JSON NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_locations_business (business_id, is_active),
  CONSTRAINT fk_locations_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Membership = the ONLY source of a user's role in a business. user_id is NULL until an invite is accepted.
-- Access requires status='active' AND user_id = caller: an invited e-mail address alone grants nothing.
CREATE TABLE business_members (
  id                CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  business_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id           CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  email             VARCHAR(254) NOT NULL,
  name              VARCHAR(150) NULL,
  role              ENUM('owner','manager','cashier') NOT NULL,
  status            ENUM('invited','active','inactive') NOT NULL,
  invited_by        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  invite_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  invite_expires_at DATETIME(3) NULL,
  joined_at         DATETIME(3) NULL,
  created_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_member_user (business_id, user_id),
  UNIQUE KEY uq_member_email (business_id, email),
  UNIQUE KEY uq_member_invite (invite_token_hash),
  KEY idx_member_user (user_id, status),
  CONSTRAINT fk_member_business FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE RESTRICT,
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Optional outlet restriction. No rows for a member = access to all outlets of the business.
CREATE TABLE member_outlets (
  member_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  location_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (member_id, location_id),
  CONSTRAINT fk_mo_member FOREIGN KEY (member_id) REFERENCES business_members(id) ON DELETE CASCADE,
  CONSTRAINT fk_mo_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE audit_logs (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  business_id    CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  actor_user_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  action         VARCHAR(80) NOT NULL,
  entity_type    VARCHAR(60) NULL,
  entity_id      VARCHAR(64) NULL,
  before_json    JSON NULL,
  after_json     JSON NULL,
  ip             VARCHAR(45) NULL,
  user_agent     VARCHAR(255) NULL,
  request_id     CHAR(36) NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_audit_business (business_id, created_at),
  KEY idx_audit_actor (actor_user_id, created_at),
  KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
