<?php
declare(strict_types=1);

/**
 * App Stats - Point d'entrée API REST en PHP pur.
 *
 * Routes supportées (via .htaccess / router.php ou paramètre ?route=...) :
 *   POST /api/v1/track        (ou POST /api.php / ?route=track)
 *   GET  /api/v1/stats        (ou ?route=stats)
 *   GET  /api/v1/export.csv   (ou ?route=export.csv)
 *   GET  /api/v1/health       (ou ?route=health)
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Access-Control-Allow-Origin: ' . $config['cors_origin']);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Admin-Key');
header('X-Content-Type-Options: nosniff');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function sendJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getHeaderValue(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey])) {
        return trim($_SERVER[$serverKey]);
    }
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            $target = strtolower($name);
            foreach ($all as $k => $v) {
                if (strtolower((string)$k) === $target && is_string($v)) {
                    return trim($v);
                }
            }
        }
    }
    return '';
}

function cleanStr(mixed $value, int $maxLen = 64, string $default = 'unknown'): string
{
    if ($value === null || (!is_string($value) && !is_numeric($value))) {
        return $default;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return $default;
    }
    $text = preg_replace('/[^\p{L}\p{N}\s.\-+\/():@_]/u', '', $text) ?? '';
    $text = trim(substr($text, 0, $maxLen));
    return $text !== '' ? $text : $default;
}

function normalizeOs(mixed $raw): string
{
    $cleaned = cleanStr($raw, 32, 'Unknown');
    $low = strtolower($cleaned);
    $map = [
        'win' => 'Windows',
        'windows' => 'Windows',
        'win32' => 'Windows',
        'cygwin' => 'Windows',
        'msys' => 'Windows',
        'darwin' => 'macOS',
        'macos' => 'macOS',
        'mac os' => 'macOS',
        'mac os x' => 'macOS',
        'osx' => 'macOS',
        'linux' => 'Linux',
        'android' => 'Android',
        'ios' => 'iOS',
        'freebsd' => 'FreeBSD',
        'openbsd' => 'OpenBSD',
        'netbsd' => 'NetBSD',
    ];
    if (isset($map[$low])) {
        return $map[$low];
    }
    if (str_starts_with($low, 'windows')) {
        return 'Windows';
    }
    if (str_starts_with($low, 'mac') || str_contains($low, 'darwin')) {
        return 'macOS';
    }
    if (str_contains($low, 'linux')) {
        return 'Linux';
    }
    return $cleaned;
}

function normalizeCountry(mixed $raw): string
{
    if (!is_string($raw) || trim($raw) === '') {
        return 'UNKNOWN';
    }
    $code = strtoupper(trim($raw));
    if (strlen($code) > 2) {
        $sep = str_contains($code, '_') ? '_' : (str_contains($code, '-') ? '-' : null);
        if ($sep !== null) {
            $parts = explode($sep, $code, 2);
            $part = explode('.', $parts[1], 2)[0];
            $part = explode('@', $part, 2)[0];
            $code = $part;
        }
    }
    if (preg_match('/^[A-Z]{2}$/', $code) === 1 && $code !== 'XX' && $code !== 'T1') {
        return $code;
    }
    return 'UNKNOWN';
}

function detectHeaderCountry(): string
{
    $headers = [
        'CF-IPCountry',
        'X-Vercel-IP-Country',
        'CloudFront-Viewer-Country',
        'X-Country-Code',
        'X-AppEngine-Country',
    ];
    foreach ($headers as $hdr) {
        $val = getHeaderValue($hdr);
        if ($val !== '') {
            $code = normalizeCountry($val);
            if ($code !== 'UNKNOWN') {
                return $code;
            }
        }
    }
    return 'UNKNOWN';
}

function getClientIpForRateLimit(): string
{
    $xff = getHeaderValue('X-Forwarded-For');
    if ($xff !== '') {
        return trim(explode(',', $xff, 2)[0]);
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function checkAdminKey(array $config): bool
{
    $expected = (string)($config['admin_key'] ?? '');
    if ($expected === '') {
        return true;
    }
    $provided = getHeaderValue('X-Admin-Key');
    if ($provided === '' && isset($_GET['key']) && is_string($_GET['key'])) {
        $provided = $_GET['key'];
    }
    return hash_equals($expected, $provided);
}

function resolveRoute(string $method): string
{
    if (isset($_GET['route']) && is_string($_GET['route']) && $_GET['route'] !== '') {
        return ltrim(strtolower(trim($_GET['route'])), '/');
    }
    $uriPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
    if (preg_match('#/api(?:/v1)?/(track|stats|export\.csv|health)/?$#i', $uriPath, $m) === 1) {
        return strtolower($m[1]);
    }
    if ($method === 'POST' && preg_match('#/api\.php/?$#i', $uriPath) === 1) {
        return 'track';
    }
    if ($method === 'GET' && preg_match('#/health/?$#i', $uriPath) === 1) {
        return 'health';
    }
    return '';
}

$route = resolveRoute($method);

if ($route === 'health' && $method === 'GET') {
    sendJson(200, ['status' => 'ok', 'version' => $config['version']]);
}

try {
    $db = new StatsDb($config);
} catch (Throwable $e) {
    sendJson(500, ['error' => 'Database connection failed']);
}

if ($route === 'track' && $method === 'POST') {
    if (!$db->checkRateLimit(getClientIpForRateLimit(), (int)$config['rate_limit'])) {
        sendJson(429, ['error' => 'Rate limit exceeded']);
    }

    $expectedApiKey = (string)($config['api_key'] ?? '');
    if ($expectedApiKey !== '') {
        $provided = getHeaderValue('X-API-Key');
        if (!hash_equals($expectedApiKey, $provided)) {
            sendJson(401, ['error' => 'Invalid or missing API key']);
        }
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) === 0 || strlen($rawBody) > 16384) {
        sendJson(400, ['error' => 'Payload size must be between 1 and 16384 bytes']);
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data) || (count($data) > 0 && array_values($data) === $data)) {
        sendJson(400, ['error' => 'Invalid JSON object body']);
    }

    $headerCountry = detectHeaderCountry();
    $clientCountry = normalizeCountry($data['country'] ?? null);
    $country = $headerCountry !== 'UNKNOWN' ? $headerCountry : $clientCountry;

    $record = [
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'app_name' => cleanStr($data['app_name'] ?? null, 64, 'default-app'),
        'app_version' => cleanStr($data['app_version'] ?? null, 32, '0.0.0'),
        'os' => normalizeOs($data['os'] ?? null),
        'os_version' => cleanStr($data['os_version'] ?? null, 48, 'unknown'),
        'arch' => cleanStr($data['arch'] ?? null, 24, 'unknown'),
        'country' => $country,
        'client_id' => cleanStr($data['client_id'] ?? null, 64, 'anonymous'),
        'sdk' => strtolower(cleanStr($data['sdk'] ?? null, 24, 'custom')),
        'event' => cleanStr($data['event'] ?? null, 48, 'launch'),
    ];

    $id = $db->insertEvent($record);
    sendJson(201, ['status' => 'ok', 'id' => $id, 'country' => $country]);
}

if ($route === 'stats' && $method === 'GET') {
    if (!checkAdminKey($config)) {
        sendJson(401, ['error' => 'Admin key required']);
    }
    $appName = isset($_GET['app']) && is_string($_GET['app']) ? $_GET['app'] : 'all';
    $daysRaw = isset($_GET['days']) && is_string($_GET['days']) ? $_GET['days'] : '30';
    $days = null;
    if ($daysRaw !== 'all') {
        $parsedDays = filter_var($daysRaw, FILTER_VALIDATE_INT);
        $days = $parsedDays !== false ? max(1, min(3650, $parsedDays)) : 30;
    }

    sendJson(200, $db->getStats($appName, $days));
}

if ($route === 'export.csv' && $method === 'GET') {
    if (!checkAdminKey($config)) {
        sendJson(401, ['error' => 'Admin key required']);
    }
    $appName = isset($_GET['app']) && is_string($_GET['app']) ? $_GET['app'] : 'all';
    $daysRaw = isset($_GET['days']) && is_string($_GET['days']) ? $_GET['days'] : 'all';
    $days = ($daysRaw !== 'all' && ctype_digit($daysRaw)) ? max(1, min(3650, (int)$daysRaw)) : null;

    $csv = $db->exportCsv($appName, $days);
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="app-stats.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

sendJson(404, ['error' => 'Endpoint not found']);
