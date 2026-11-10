ALTER TABLE users
  DROP KEY idx_users_deletion,
  DROP COLUMN anonymised_at,
  DROP COLUMN deletion_scheduled_at,
  DROP COLUMN deletion_requested_at,
  DROP COLUMN must_change_password;
