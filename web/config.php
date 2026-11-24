<?php
declare(strict_types=1);

/**
 * App Stats - Configuration PHP.
 *
 * Vous pouvez modifier les valeurs ci-dessous directement (hébergement mutualisé)
 * ou utiliser les variables d'environnement (Docker / VPS / CI).
 */

return [
    'version' => '1.0.0',

    // Pilote de base de données : 'sqlite' (recommandé, zéro config) ou 'mysql'
    'db_driver' => getenv('APP_STATS_DB_DRIVER') ?: 'sqlite',

    // Chemin du fichier SQLite (utilisé si db_driver === 'sqlite')
    'sqlite_path' => getenv('APP_STATS_DB') ?: (__DIR__ . '/data/stats.db'),

    // Paramètres MySQL / MariaDB (utilisés uniquement si db_driver === 'mysql')
    'mysql_host' => getenv('APP_STATS_MYSQL_HOST') ?: '127.0.0.1',
    'mysql_port' => (int)(getenv('APP_STATS_MYSQL_PORT') ?: 3306),
    'mysql_db'   => getenv('APP_STATS_MYSQL_DB') ?: 'app_stats',
    'mysql_user' => getenv('APP_STATS_MYSQL_USER') ?: 'root',
    'mysql_pass' => getenv('APP_STATS_MYSQL_PASS') ?: '',

    // Clé optionnelle exigée dans l'en-tête HTTP X-API-Key pour POST /api/v1/track
    'api_key' => getenv('APP_STATS_API_KEY') ?: '',

    // Clé optionnelle exigée (X-Admin-Key ou ?key=...) pour lire /stats et /export.csv
    'admin_key' => getenv('APP_STATS_ADMIN_KEY') ?: '',

    // Requêtes POST max par minute et par IP (0 = désactivé)
    'rate_limit' => (int)(getenv('APP_STATS_RATE_LIMIT') !== false ? getenv('APP_STATS_RATE_LIMIT') : 120),

    // En-tête CORS Access-Control-Allow-Origin
    'cors_origin' => getenv('APP_STATS_CORS_ORIGIN') ?: '*',
];
