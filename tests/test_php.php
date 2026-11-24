<?php
declare(strict_types=1);

/**
 * Tests unitaires PHP purs (aucune dépendance Composer / PHPUnit).
 * Exécution : php tests/test_php.php
 */

require_once __DIR__ . '/../web/db.php';

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$tmpDb = sys_get_temp_dir() . '/app_stats_test_' . uniqid('', true) . '.db';
try {
    $db = new StatsDb([
        'db_driver' => 'sqlite',
        'sqlite_path' => $tmpDb,
    ]);

    $id1 = $db->insertEvent([
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'app_name' => 'demo',
        'app_version' => '1.0.0',
        'os' => 'Windows',
        'os_version' => '11',
        'arch' => 'x86_64',
        'country' => 'FR',
        'client_id' => 'client-1',
        'sdk' => 'python',
        'event' => 'launch',
    ]);
    assertTrue($id1 > 0, 'First insert returns positive ID');

    $db->insertEvent([
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'app_name' => 'demo',
        'app_version' => '1.1.0',
        'os' => 'Linux',
        'os_version' => '6.8',
        'arch' => 'x86_64',
        'country' => 'CA',
        'client_id' => 'client-2',
        'sdk' => 'rust',
        'event' => 'launch',
    ]);

    $db->insertEvent([
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'app_name' => 'demo',
        'app_version' => '1.1.0',
        'os' => 'Linux',
        'os_version' => '6.8',
        'arch' => 'x86_64',
        'country' => 'CA',
        'client_id' => 'client-2',
        'sdk' => 'rust',
        'event' => 'launch',
    ]);

    $stats = $db->getStats('demo', 30);
    assertTrue($stats['totals']['total_uses'] === 3, 'Total uses should be 3');
    assertTrue($stats['totals']['unique_users'] === 2, 'Unique users should be 2');
    assertTrue($stats['totals']['versions_count'] === 2, 'Versions count should be 2');
    assertTrue($stats['totals']['countries_count'] === 2, 'Countries count should be 2');

    $csv = $db->exportCsv('demo', 30);
    assertTrue(str_contains($csv, 'app_name,app_version'), 'CSV should contain header');
    assertTrue(str_contains($csv, 'demo,1.0.0,Windows'), 'CSV should contain inserted row');

    assertTrue($db->checkRateLimit('127.0.0.1', 2) === true, 'Rate limit hit 1 allowed');
    assertTrue($db->checkRateLimit('127.0.0.1', 2) === true, 'Rate limit hit 2 allowed');
    assertTrue($db->checkRateLimit('127.0.0.1', 2) === false, 'Rate limit hit 3 blocked');

    echo "ALL PHP DB TESTS PASSED!\n";
} finally {
    foreach ([$tmpDb, $tmpDb . '-wal', $tmpDb . '-shm'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
