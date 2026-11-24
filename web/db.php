<?php
declare(strict_types=1);

/**
 * App Stats - Couche base de données PDO (SQLite par défaut, MySQL/MariaDB optionnel).
 */
final class StatsDb
{
    private PDO $pdo;
    private string $driver;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->driver = strtolower((string)($config['db_driver'] ?? 'sqlite'));

        if ($this->driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string)$config['mysql_host'],
                (int)$config['mysql_port'],
                (string)$config['mysql_db']
            );
            $this->pdo = new PDO(
                $dsn,
                (string)$config['mysql_user'],
                (string)$config['mysql_pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } else {
            $this->driver = 'sqlite';
            $path = (string)$config['sqlite_path'];
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->pdo = new PDO(
                'sqlite:' . $path,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 15,
                ]
            );
            $this->pdo->exec('PRAGMA journal_mode = WAL;');
            $this->pdo->exec('PRAGMA synchronous = NORMAL;');
        }

        $this->initSchema();
    }

    private function initSchema(): void
    {
        if ($this->driver === 'mysql') {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS events (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    created_at  VARCHAR(24) NOT NULL,
                    app_name    VARCHAR(64) NOT NULL,
                    app_version VARCHAR(32) NOT NULL,
                    os          VARCHAR(32) NOT NULL,
                    os_version  VARCHAR(48) NOT NULL,
                    arch        VARCHAR(24) NOT NULL,
                    country     VARCHAR(16) NOT NULL,
                    client_id   VARCHAR(64) NOT NULL,
                    sdk         VARCHAR(24) NOT NULL,
                    event       VARCHAR(48) NOT NULL,
                    INDEX idx_events_created_at (created_at),
                    INDEX idx_events_app_name (app_name),
                    INDEX idx_events_client_id (client_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
            );
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS rate_limits (
                    bucket_key VARCHAR(80) PRIMARY KEY,
                    hits       INT NOT NULL DEFAULT 0,
                    expires_at INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
            );
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS events (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at  TEXT NOT NULL,
                app_name    TEXT NOT NULL,
                app_version TEXT NOT NULL,
                os          TEXT NOT NULL,
                os_version  TEXT NOT NULL,
                arch        TEXT NOT NULL,
                country     TEXT NOT NULL,
                client_id   TEXT NOT NULL,
                sdk         TEXT NOT NULL,
                event       TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_events_created_at ON events(created_at);
            CREATE INDEX IF NOT EXISTS idx_events_app_name ON events(app_name);
            CREATE INDEX IF NOT EXISTS idx_events_client_id ON events(client_id);
            CREATE TABLE IF NOT EXISTS rate_limits (
                bucket_key TEXT PRIMARY KEY,
                hits       INTEGER NOT NULL DEFAULT 0,
                expires_at INTEGER NOT NULL
            );'
        );
    }

    /**
     * Vérifie le rate-limit sans jamais stocker d'IP en clair (hash SHA-256 par fenêtre d'1 minute).
     */
    public function checkRateLimit(string $clientIp, int $maxPerMinute): bool
    {
        if ($maxPerMinute <= 0) {
            return true;
        }
        $now = time();
        $minuteBucket = intdiv($now, 60);
        $bucketKey = hash('sha256', $clientIp . ':' . $minuteBucket);
        $expiresAt = ($minuteBucket + 2) * 60;

        // Nettoyage opportuniste des fenêtres expirées
        if (random_int(1, 25) === 1) {
            $del = $this->pdo->prepare('DELETE FROM rate_limits WHERE expires_at < ?');
            $del->execute([$now]);
        }

        $sel = $this->pdo->prepare('SELECT hits FROM rate_limits WHERE bucket_key = ?');
        $sel->execute([$bucketKey]);
        $row = $sel->fetch();

        if ($row === false) {
            $ins = $this->pdo->prepare('INSERT INTO rate_limits (bucket_key, hits, expires_at) VALUES (?, 1, ?)');
            try {
                $ins->execute([$bucketKey, $expiresAt]);
            } catch (PDOException) {
                $upd = $this->pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket_key = ?');
                $upd->execute([$bucketKey]);
            }
            return true;
        }

        if ((int)$row['hits'] >= $maxPerMinute) {
            return false;
        }

        $upd = $this->pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket_key = ?');
        $upd->execute([$bucketKey]);
        return true;
    }

    /**
     * @param array<string, string> $record
     */
    public function insertEvent(array $record): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO events (
                created_at, app_name, app_version, os, os_version,
                arch, country, client_id, sdk, event
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $record['created_at'],
            $record['app_name'],
            $record['app_version'],
            $record['os'],
            $record['os_version'],
            $record['arch'],
            $record['country'],
            $record['client_id'],
            $record['sdk'],
            $record['event'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(?string $appName, ?int $days): array
    {
        $clauses = [];
        $params = [];

        if ($appName !== null && $appName !== '' && $appName !== 'all') {
            $clauses[] = 'app_name = ?';
            $params[] = $appName;
        }
        if ($days !== null && $days > 0) {
            $since = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
            $clauses[] = 'created_at >= ?';
            $params[] = $since;
        }

        $where = count($clauses) > 0 ? ('WHERE ' . implode(' AND ', $clauses)) : '';
        return [$where, $params];
    }

    /**
     * @return array<string, mixed>
     */
    public function getStats(?string $appName = null, ?int $days = 30): array
    {
        [$where, $params] = $this->buildWhere($appName, $days);
        $todayPrefix = gmdate('Y-m-d');

        $apps = $this->pdo
            ->query('SELECT DISTINCT app_name FROM events ORDER BY app_name ASC')
            ->fetchAll(PDO::FETCH_COLUMN);

        $totalsStmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS total_uses,
                COUNT(DISTINCT client_id) AS unique_users,
                COUNT(DISTINCT CASE WHEN country != 'UNKNOWN' THEN country END) AS countries_count,
                COUNT(DISTINCT app_version) AS versions_count
            FROM events
            {$where}"
        );
        $totalsStmt->execute($params);
        $totalsRow = $totalsStmt->fetch() ?: [];

        $todayWhere = $where !== '' ? "{$where} AND" : 'WHERE';
        $todayStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS uses_today
            FROM events
            {$todayWhere} SUBSTR(created_at, 1, 10) = ?"
        );
        $todayStmt->execute(array_merge($params, [$todayPrefix]));
        $todayRow = $todayStmt->fetch() ?: [];

        $dailyStmt = $this->pdo->prepare(
            "SELECT
                SUBSTR(created_at, 1, 10) AS date,
                COUNT(*) AS uses,
                COUNT(DISTINCT client_id) AS unique_users
            FROM events
            {$where}
            GROUP BY SUBSTR(created_at, 1, 10)
            ORDER BY date ASC"
        );
        $dailyStmt->execute($params);
        $dailyUses = array_map(static fn(array $r): array => [
            'date' => (string)$r['date'],
            'uses' => (int)$r['uses'],
            'unique_users' => (int)$r['unique_users'],
        ], $dailyStmt->fetchAll());

        $versionStmt = $this->pdo->prepare(
            "SELECT
                app_version AS version,
                COUNT(*) AS uses,
                COUNT(DISTINCT client_id) AS unique_users
            FROM events
            {$where}
            GROUP BY app_version
            ORDER BY uses DESC
            LIMIT 25"
        );
        $versionStmt->execute($params);
        $byVersion = array_map(static fn(array $r): array => [
            'version' => (string)$r['version'],
            'uses' => (int)$r['uses'],
            'unique_users' => (int)$r['unique_users'],
        ], $versionStmt->fetchAll());

        $osStmt = $this->pdo->prepare(
            "SELECT
                os,
                COUNT(*) AS uses,
                COUNT(DISTINCT client_id) AS unique_users
            FROM events
            {$where}
            GROUP BY os
            ORDER BY uses DESC
            LIMIT 20"
        );
        $osStmt->execute($params);
        $byOs = array_map(static fn(array $r): array => [
            'os' => (string)$r['os'],
            'uses' => (int)$r['uses'],
            'unique_users' => (int)$r['unique_users'],
        ], $osStmt->fetchAll());

        $countryStmt = $this->pdo->prepare(
            "SELECT
                country,
                COUNT(*) AS uses,
                COUNT(DISTINCT client_id) AS unique_users
            FROM events
            {$where}
            GROUP BY country
            ORDER BY uses DESC
            LIMIT 50"
        );
        $countryStmt->execute($params);
        $byCountry = array_map(static fn(array $r): array => [
            'country' => (string)$r['country'],
            'uses' => (int)$r['uses'],
            'unique_users' => (int)$r['unique_users'],
        ], $countryStmt->fetchAll());

        $sdkStmt = $this->pdo->prepare(
            "SELECT
                sdk,
                COUNT(*) AS uses,
                COUNT(DISTINCT client_id) AS unique_users
            FROM events
            {$where}
            GROUP BY sdk
            ORDER BY uses DESC
            LIMIT 20"
        );
        $sdkStmt->execute($params);
        $bySdk = array_map(static fn(array $r): array => [
            'sdk' => (string)$r['sdk'],
            'uses' => (int)$r['uses'],
            'unique_users' => (int)$r['unique_users'],
        ], $sdkStmt->fetchAll());

        $recentStmt = $this->pdo->prepare(
            "SELECT
                id, created_at, app_name, app_version, os, os_version,
                arch, country, sdk, event
            FROM events
            {$where}
            ORDER BY id DESC
            LIMIT 30"
        );
        $recentStmt->execute($params);
        $recentEvents = $recentStmt->fetchAll();

        return [
            'apps' => array_values($apps),
            'filter' => [
                'app' => $appName ?: 'all',
                'days' => $days ?? 'all',
            ],
            'totals' => [
                'total_uses' => (int)($totalsRow['total_uses'] ?? 0),
                'unique_users' => (int)($totalsRow['unique_users'] ?? 0),
                'uses_today' => (int)($todayRow['uses_today'] ?? 0),
                'countries_count' => (int)($totalsRow['countries_count'] ?? 0),
                'versions_count' => (int)($totalsRow['versions_count'] ?? 0),
            ],
            'daily_uses' => $dailyUses,
            'by_version' => $byVersion,
            'by_os' => $byOs,
            'by_country' => $byCountry,
            'by_sdk' => $bySdk,
            'recent_events' => $recentEvents,
        ];
    }

    public function exportCsv(?string $appName = null, ?int $days = null): string
    {
        [$where, $params] = $this->buildWhere($appName, $days);
        $stmt = $this->pdo->prepare(
            "SELECT id, created_at, app_name, app_version, os, os_version, arch, country, sdk, event
            FROM events
            {$where}
            ORDER BY id DESC
            LIMIT 50000"
        );
        $stmt->execute($params);

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }
        fputcsv($fp, ['id', 'created_at', 'app_name', 'app_version', 'os', 'os_version', 'arch', 'country', 'sdk', 'event'], ',', '"', '');
        while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
            fputcsv($fp, $row, ',', '"', '');
        }
        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);
        return $csv !== false ? $csv : '';
    }
}
