<?php
declare(strict_types=1);

/**
 * Routeur pour le serveur PHP intégré (développement local et tests CI) :
 *   php -S localhost:8080 -t web web/router.php
 */

$path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

// Bloquer l'accès direct aux fichiers sensibles
if (preg_match('#^/(data/|config\.php|db\.php|router\.php)#i', $path) === 1) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Routes API (/api/v1/*, /api/*, /health)
if (preg_match('#^/(api(?:/v1)?/|health$)#i', $path) === 1) {
    require __DIR__ . '/api.php';
    return true;
}

// Fichiers statiques existants (styles.css, app.js, etc.)
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}

// Page d'accueil du Dashboard
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Not found']);
return true;
