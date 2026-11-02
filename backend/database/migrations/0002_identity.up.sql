-- Identity. User ids are CHAR(36) UUIDs so Supabase auth.users ids can be preserved on import.
CREATE TABLE users (
  id                  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  email               VARCHAR(254) NOT NULL,
  password_hash       VARCHAR(255) NULL,
  legacy_bcrypt_hash  VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'imported GoTrue bcrypt; verified once then NULLed',
  email_verified_at   DATETIME(3) NULL,
  phone               VARCHAR(32) NULL,
  phone_verified_at   DATETIME(3) NULL,
  status              ENUM('active','disabled','pending_deletion') NOT NULL DEFAULT 'active',
  last_login_at       DATETIME(3) NULL,
  created_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  deleted_at          DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_profiles (
  user_id        CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  full_name      VARCHAR(150) NULL,
  address        VARCHAR(300) NULL,
  currency       CHAR(3) NOT NULL DEFAULT 'NGN',
  avatar_path    VARCHAR(255) NULL,
  notification_prefs JSON NULL,
  created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per login (device). Only SHA-256 hashes of tokens are stored.
CREATE TABLE auth_sessions (
  id                 CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id            CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  device_id          VARCHAR(64) NULL,
  device_name        VARCHAR(120) NULL,
  platform           VARCHAR(32) NULL,
  access_hash        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  access_expires_at  DATETIME(3) NOT NULL,
  refresh_hash       CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prev_refresh_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL COMMENT 'previous refresh token: presenting it again = theft signal',
  refresh_expires_at DATETIME(3) NOT NULL,
  ip                 VARCHAR(45) NULL,
  user_agent         VARCHAR(255) NULL,
  created_at         DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_used_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  revoked_at         DATETIME(3) NULL,
  revoked_reason     VARCHAR(40) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_access (access_hash),
  UNIQUE KEY uq_sessions_refresh (refresh_hash),
  KEY idx_sessions_prev_refresh (prev_refresh_hash),
  KEY idx_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Single-use tokens e-mailed to users (verify e-mail, reset password).
CREATE TABLE user_tokens (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purpose     ENUM('verify_email','reset_password') NOT NULL,
  token_hash  CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at  DATETIME(3) NOT NULL,
  used_at     DATETIME(3) NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_tokens_hash (token_hash),
  KEY idx_user_tokens_user (user_id, purpose, used_at),
  CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Platform administrators. NEVER derived from a profile/role field or request data.
-- Rows are created only by bin/make-admin.php (CLI) or by an existing admin with step-up (later phase).
CREATE TABLE platform_admins (
  user_id     CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (user_id),
  CONSTRAINT fk_admin_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
