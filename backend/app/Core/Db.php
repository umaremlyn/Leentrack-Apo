<?php
declare(strict_types=1);

namespace LeenTrack\Core;

use PDO;

/** PDO factory. Prepared statements are real (not emulated) for the application connection. */
final class Db
{
    /** @param array{host:string,port:int,name:string,user:string,pass:string,socket:string} $c */
    public static function connect(array $c, bool $multiStatement = false): PDO
    {
        $dsn = $c['socket'] !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => $multiStatement,   // only the migrator needs multi-statement files
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        // UTC everywhere; strict mode so bad data fails instead of being silently truncated.
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET SESSION transaction_isolation = 'READ-COMMITTED'");
        return $pdo;
    }

    /**
     * Run $fn inside a transaction; retries once on deadlock (1213) / lock wait timeout (1205).
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(PDO $pdo, callable $fn, int $attempts = 2): mixed
    {
        for ($i = 1; ; $i++) {
            $pdo->beginTransaction();
            try {
                $r = $fn($pdo);
                $pdo->commit();
                return $r;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $code = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
                if ($i < $attempts && in_array($code, [1213, 1205], true)) {
                    usleep(random_int(20_000, 80_000));
                    continue;
                }
                throw $e;
            }
        }
    }
}
