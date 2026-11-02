#!/usr/bin/env php
<?php
declare(strict_types=1);

/** Grants platform-admin to an EXISTING user. CLI only: there is deliberately no HTTP path that can do this. */
if (PHP_SAPI !== 'cli') { exit(1); }
$app = require dirname(__DIR__) . '/app/bootstrap.php';
$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') { fwrite(STDERR, "Usage: php bin/make-admin.php user@example.com\n"); exit(2); }
$pdo = $app->pdo();
$st = $pdo->prepare('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL');
$st->execute([$email]);
$id = $st->fetchColumn();
if (!$id) { fwrite(STDERR, "No such user.\n"); exit(1); }
$pdo->prepare('INSERT IGNORE INTO platform_admins (user_id) VALUES (?)')->execute([$id]);
$pdo->prepare('INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id) VALUES (NULL, ?, ?, ?)')->execute(['admin.granted_cli', 'user', $id]);
echo "Platform admin granted to $email\n";
