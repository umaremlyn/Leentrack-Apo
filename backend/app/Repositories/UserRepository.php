<?php
declare(strict_types=1);

namespace LeenTrack\Repositories;

use LeenTrack\Core\Clock;
use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $u */
    public function insert(array $u): void
    {
        $st = $this->pdo->prepare('INSERT INTO users (id,email,password_hash,legacy_bcrypt_hash,email_verified_at,phone,status) VALUES (?,?,?,?,?,?,?)');
        $st->execute([$u['id'], $u['email'], $u['password_hash'] ?? null, $u['legacy_bcrypt_hash'] ?? null, $u['email_verified_at'] ?? null, $u['phone'] ?? null, $u['status'] ?? 'active']);
    }

    /** @return array<string,mixed>|null */
    public function profile(string $userId): ?array
    {
        $st = $this->pdo->prepare('SELECT full_name,address,currency,avatar_path,notification_prefs FROM user_profiles WHERE user_id = ?');
        $st->execute([$userId]);
        return $st->fetch() ?: null;
    }

    public function insertProfile(string $userId, ?string $fullName, string $currency, ?string $address): void
    {
        $this->pdo->prepare('INSERT INTO user_profiles (user_id, full_name, currency, address) VALUES (?,?,?,?)')->execute([$userId, $fullName, $currency, $address]);
    }

    /** @param bool $changed true when the USER chose a new password (clears the must-change flag); false for a silent re-hash of the same password */
    public function setPassword(string $userId, string $hash, bool $changed = true): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ?, legacy_bcrypt_hash = NULL' . ($changed ? ', must_change_password = 0' : '') . ' WHERE id = ?')->execute([$hash, $userId]);
    }

    public function setMustChangePassword(string $userId, bool $flag): void
    {
        $this->pdo->prepare('UPDATE users SET must_change_password = ? WHERE id = ?')->execute([(int) $flag, $userId]);
    }

    public function requestDeletion(string $userId, string $nowSql, string $dueSql): void
    {
        $this->pdo->prepare("UPDATE users SET status = 'pending_deletion', deletion_requested_at = ?, deletion_scheduled_at = ? WHERE id = ? AND status = 'active'")->execute([$nowSql, $dueSql, $userId]);
    }

    public function cancelDeletion(string $userId): void
    {
        $this->pdo->prepare("UPDATE users SET status = 'active', deletion_requested_at = NULL, deletion_scheduled_at = NULL WHERE id = ? AND status = 'pending_deletion' AND anonymised_at IS NULL")->execute([$userId]);
    }

    /** @return list<string> ids whose grace period has ended */
    public function dueDeletions(string $nowSql, int $limit = 50): array
    {
        $st = $this->pdo->prepare("SELECT id FROM users WHERE status = 'pending_deletion' AND anonymised_at IS NULL AND deletion_scheduled_at <= ? ORDER BY deletion_scheduled_at, id LIMIT $limit");
        $st->execute([$nowSql]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Blank every personal field; the row stays because sales.user_id (and audit actors) point at it. */
    public function anonymise(string $userId, string $nowSql): void
    {
        $this->pdo->prepare("UPDATE users SET email = ?, password_hash = NULL, legacy_bcrypt_hash = NULL, phone = NULL, phone_verified_at = NULL, status = 'disabled', must_change_password = 0, deletion_scheduled_at = NULL, anonymised_at = ?, deleted_at = ? WHERE id = ?")
            ->execute(['deleted-' . $userId . '@deleted.invalid', $nowSql, $nowSql, $userId]);
        $this->pdo->prepare('UPDATE user_profiles SET full_name = NULL, address = NULL, avatar_path = NULL, notification_prefs = NULL WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM user_tokens WHERE user_id = ?')->execute([$userId]);
        $this->pdo->prepare('DELETE FROM auth_sessions WHERE user_id = ?')->execute([$userId]);
    }

    public function setAvatar(string $userId, ?string $path): void
    {
        $this->pdo->prepare('UPDATE user_profiles SET avatar_path = ? WHERE user_id = ?')->execute([$path, $userId]);
    }

    public function markLogin(string $userId): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([Clock::sql(), $userId]);
    }

    public function markEmailVerified(string $userId): void
    {
        $this->pdo->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')->execute([Clock::sql(), $userId]);
    }

    public function isPlatformAdmin(string $userId): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM platform_admins WHERE user_id = ?');
        $st->execute([$userId]);
        return (bool) $st->fetchColumn();
    }

    /** @param array<string,mixed> $fields only: phone */
    public function updatePhone(string $userId, ?string $phone): void
    {
        $this->pdo->prepare('UPDATE users SET phone = ?, phone_verified_at = NULL WHERE id = ?')->execute([$phone, $userId]);
    }

    public function updateProfile(string $userId, ?string $fullName, ?string $address, ?string $currency): void
    {
        $this->pdo->prepare('UPDATE user_profiles SET full_name = COALESCE(?, full_name), address = COALESCE(?, address), currency = COALESCE(?, currency) WHERE user_id = ?')
            ->execute([$fullName, $address, $currency, $userId]);
    }

    // ---- single-use e-mail tokens ----

    public function createToken(string $userId, string $purpose, string $hash, string $expiresSql): void
    {
        // Only one live token per purpose: invalidate older unused ones.
        $this->pdo->prepare('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([Clock::sql(), $userId, $purpose]);
        $this->pdo->prepare('INSERT INTO user_tokens (user_id,purpose,token_hash,expires_at) VALUES (?,?,?,?)')->execute([$userId, $purpose, $hash, $expiresSql]);
    }

    /**
     * Atomically consume a token: returns user_id if it existed, was unused and unexpired; null otherwise.
     * The UPDATE ... WHERE used_at IS NULL makes consumption race-free (two requests cannot both win).
     */
    public function consumeToken(string $purpose, string $hash): ?string
    {
        $st = $this->pdo->prepare('UPDATE user_tokens SET used_at = ? WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?');
        $now = Clock::sql();
        $st->execute([$now, $hash, $purpose, $now]);
        if ($st->rowCount() !== 1) {
            return null;
        }
        $q = $this->pdo->prepare('SELECT user_id FROM user_tokens WHERE token_hash = ?');
        $q->execute([$hash]);
        return $q->fetchColumn() ?: null;
    }
}
