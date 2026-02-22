<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\MessageController;
use App\Controllers\LicenseController;
use App\Middleware\AuthMiddleware;

return function($app) {
    // Публичный эндпоинт для регистрации лицензии
    $app->post('/api/v1/licenses', [LicenseController::class, 'register']);

    // Группа защищённых маршрутов
    $app->group('/api/v1', function (RouteCollectorProxy $group) {
        $group->get('/licenses', [LicenseController::class, 'list']); // список лицензий организации

        $group->post('/messages', [MessageController::class, 'send']);
        $group->get('/messages', [MessageController::class, 'list']);
        $group->get('/messages/{id}', [MessageController::class, 'get']);
        $group->get('/messages/{id}/file', [MessageController::class, 'file']);
        $group->delete('/messages/{id}', [MessageController::class, 'delete']);
    })->add(AuthMiddleware::class);
};