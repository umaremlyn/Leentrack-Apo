-- must_change_password: set when a migrated (legacy) account signs in with a password that fails today's policy. Soft: clients prompt, nothing is blocked.
-- deletion_*: 14-day grace between a deletion request and the purge job (sign-in is disabled meanwhile; the user can cancel).
-- anonymised_at: the user row is kept (sales.user_id must stay valid) but every personal field is blanked by the purge job.
ALTER TABLE users
  ADD COLUMN must_change_password   TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN deletion_requested_at  DATETIME(3) NULL AFTER must_change_password,
  ADD COLUMN deletion_scheduled_at  DATETIME(3) NULL AFTER deletion_requested_at,
  ADD COLUMN anonymised_at          DATETIME(3) NULL AFTER deletion_scheduled_at,
  ADD KEY idx_users_deletion (status, deletion_scheduled_at);
