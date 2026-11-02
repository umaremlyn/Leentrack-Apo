<?php
declare(strict_types=1);

namespace LeenTrack\Core;

use PDO;

/**
 * File-based migrator. Files: database/migrations/NNNN_name.up.sql (+ optional .down.sql).
 * NOTE: MySQL DDL causes implicit commits, so a migration is NOT atomic. Keep each file small and
 * idempotent where possible (IF NOT EXISTS); the runner records a version only after the whole file succeeds.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(20) NOT NULL PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            checksum CHAR(64) NOT NULL,
            batch INT NOT NULL,
            applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }

    /** @return array<string,array{name:string,up:string,down:?string}> keyed by version */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/*.up.sql') ?: [] as $f) {
            if (!preg_match('/^(\d{4})_([a-z0-9_]+)\.up\.sql$/', basename($f), $m)) {
                throw new \RuntimeException('Bad migration filename: ' . basename($f));
            }
            $down = $this->dir . "/{$m[1]}_{$m[2]}.down.sql";
            $out[$m[1]] = ['name' => $m[2], 'up' => $f, 'down' => is_file($down) ? $down : null];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,array{name:string,checksum:string,batch:int}> */
    public function applied(): array
    {
        $rows = $this->pdo->query('SELECT version,name,checksum,batch FROM schema_migrations ORDER BY version')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['version']] = ['name' => $r['name'], 'checksum' => $r['checksum'], 'batch' => (int) $r['batch']];
        }
        return $out;
    }

    /** @return list<string> versions applied now */
    public function up(): array
    {
        $applied = $this->applied();
        $avail = $this->available();
        foreach ($applied as $v => $a) {                      // tamper / drift detection
            if (!isset($avail[$v])) {
                throw new \RuntimeException("Applied migration $v is missing from disk.");
            }
            if (hash_file('sha256', $avail[$v]['up']) !== $a['checksum']) {
                throw new \RuntimeException("Migration $v was modified after being applied. Create a new migration instead.");
            }
        }
        $batch = ($applied ? max(array_column($applied, 'batch')) : 0) + 1;
        $done = [];
        foreach ($avail as $v => $m) {
            if (isset($applied[$v])) {
                continue;
            }
            $this->pdo->exec((string) file_get_contents($m['up']));
            $st = $this->pdo->prepare('INSERT INTO schema_migrations (version,name,checksum,batch) VALUES (?,?,?,?)');
            $st->execute([$v, $m['name'], hash_file('sha256', $m['up']), $batch]);
            $done[] = $v;
        }
        return $done;
    }

    /** Roll back the last batch (newest first). Refuses if a migration has no down file. @return list<string> */
    public function down(): array
    {
        $applied = $this->applied();
        if (!$applied) {
            return [];
        }
        $last = max(array_column($applied, 'batch'));
        $avail = $this->available();
        $versions = array_keys(array_filter($applied, fn ($a) => $a['batch'] === $last));
        rsort($versions);
        $done = [];
        foreach ($versions as $v) {
            $down = $avail[$v]['down'] ?? null;
            if ($down === null) {
                throw new \RuntimeException("Migration $v has no .down.sql; not safe to roll back automatically.");
            }
            $this->pdo->exec((string) file_get_contents($down));
            $this->pdo->prepare('DELETE FROM schema_migrations WHERE version = ?')->execute([$v]);
            $done[] = $v;
        }
        return $done;
    }

    /** @return list<array{version:string,name:string,state:string}> */
    public function status(): array
    {
        $applied = $this->applied();
        $rows = [];
        foreach ($this->available() as $v => $m) {
            $rows[] = ['version' => $v, 'name' => $m['name'], 'state' => isset($applied[$v]) ? 'applied' : 'pending'];
        }
        return $rows;
    }
}
