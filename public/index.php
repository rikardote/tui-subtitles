<?php

declare(strict_types=1);

/**
 * Subtitle Processor — Web Front Controller
 */

$autoload = __DIR__ . '/../vendor/autoload.php';

if (! file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Falta el directorio vendor/. Ejecute: composer install\n";
    exit(1);
}

if (php_sapi_name() === 'cli-server') {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if ($uri !== '/' && file_exists(__DIR__ . $uri) && is_file(__DIR__ . $uri)) {
        return false;
    }
}

require $autoload;

use App\Http\Controllers\ApiController;
use App\Http\Router;

$router = new Router();

// API Endpoints
$router->get('/api/dashboard', [ApiController::class, 'dashboard']);
$router->get('/api/tree', [ApiController::class, 'tree']);
$router->get('/api/media', [ApiController::class, 'mediaList']);
$router->get('/api/media/{id}', [ApiController::class, 'mediaDetail']);
$router->post('/api/media/{id}/analyze', [ApiController::class, 'mediaAnalyze']);
$router->post('/api/media/{id}/translate', [ApiController::class, 'mediaTranslate']);
$router->post('/api/media/batch-translate', [ApiController::class, 'mediaBatchTranslate']);
$router->post('/api/media/{id}/extract', [ApiController::class, 'mediaExtract']);
$router->delete('/api/media/{id}/generated', [ApiController::class, 'mediaDeleteGenerated']);
$router->delete('/api/tracks/{id}', [ApiController::class, 'trackDelete']);
$router->get('/api/tracks/{id}/review-status', [ApiController::class, 'trackReviewStatus']);
$router->post('/api/tracks/{id}/review', [ApiController::class, 'trackReview']);
$router->post('/api/scan', [ApiController::class, 'scanLibraries']);
$router->get('/api/tasks', [ApiController::class, 'tasksList']);
$router->get('/api/tasks/active', [ApiController::class, 'activeTask']);
$router->post('/api/tasks/{id}/cancel', [ApiController::class, 'taskCancel']);
$router->get('/api/queue/status', [ApiController::class, 'queueStatus']);
$router->get('/api/jellyfin/status', [ApiController::class, 'jellyfinStatus']);
$router->post('/api/jellyfin/sync', [ApiController::class, 'jellyfinSync']);
$router->get('/api/settings', [ApiController::class, 'settingsGet']);
$router->post('/api/settings', [ApiController::class, 'settingsSave']);
$router->post('/api/settings/test-provider', [ApiController::class, 'settingsTestProvider']);

// Web UI Single Page Application (SPA)
$router->get('/', function () {
    require __DIR__ . '/../resources/views/app.php';
});

$router->dispatch();
