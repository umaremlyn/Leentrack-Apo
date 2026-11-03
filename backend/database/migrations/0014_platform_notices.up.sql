-- Announcements from the platform operator to every signed-in user (maintenance, pricing changes). The source stored a "broadcast" as an
-- audit-log row that nothing ever displayed; clients read GET /notices and show a banner.
CREATE TABLE platform_notices (
  id          CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title       VARCHAR(120) NOT NULL,
  body        VARCHAR(1000) NOT NULL,
  level       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  starts_at   DATETIME(3) NOT NULL,
  ends_at     DATETIME(3) NULL,
  created_by  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_notices_active (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
