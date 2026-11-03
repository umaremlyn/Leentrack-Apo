#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Platform-admin management. CLI only: there is deliberately no HTTP path that can grant or revoke admin rights.
 *   php bin/make-admin.php user@example.com            grant to an EXISTING user
 *   php bin/make-admin.php --revoke user@example.com   revoke (refused if it would leave no admin)
 *   php bin/make-admin.php --list                      show current admins
 */
if (PHP_SAPI !== 'cli') { exit(1); }
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = $app->pdo();
$args = array_slice($argv, 1);
$audit = fn (string $action, string $id) => $pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id) VALUES (NULL, ?, ?, ?)')->execute([$action, 'user', $id]);
$find = function (string $email) use ($pdo) { $st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL'); $st->execute([strtolower(trim($email))]); return $st->fetchColumn() ?: null; };

if (($args[0] ?? '') === '--list') {
    foreach ($pdo->query('SELECT u.email, a.created_at FROM platform_admins a JOIN users u ON u.id = a.user_id ORDER BY a.created_at')->fetchAll(PDO::FETCH_NUM) as [$e, $c]) { echo "$e\t$c\n"; }
    exit(0);
}
if (($args[0] ?? '') === '--revoke') {
    $id = $find($args[1] ?? '');
    if (!$id) { fwrite(STDERR, "No such user.\n"); exit(1); }
    $n = (int) $pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn();
    $is = $pdo->prepare('SELECT 1 FROM platform_admins WHERE user_id = ?'); $is->execute([$id]);
    if (!$is->fetchColumn()) { fwrite(STDERR, "That user is not a platform admin.\n"); exit(1); }
    if ($n <= 1) { fwrite(STDERR, "Refused: this is the only platform admin. Grant another first.\n"); exit(1); }
    $pdo->prepare('DELETE FROM platform_admins WHERE user_id = ?')->execute([$id]);
    $audit('admin.revoked_cli', (string) $id);
    echo "Platform admin revoked for {$args[1]}\n";
    exit(0);
}
$email = strtolower(trim($args[0] ?? ''));
if ($email === '') { fwrite(STDERR, "Usage: php bin/make-admin.php user@example.com | --revoke user@example.com | --list\n"); exit(2); }
$id = $find($email);
if (!$id) { fwrite(STDERR, "No such user.\n"); exit(1); }
$pdo->prepare('INSERT IGNORE INTO platform_admins (user_id) VALUES (?)')->execute([$id]);
$audit('admin.granted_cli', (string) $id);
echo "Platform admin granted to $email\n";
