<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\MessageController;
use App\Middleware\AuthMiddleware;

return function($app) {
    // Группа маршрутов /api, все требуют аутентификации
    $app->group('/api/v1', function (RouteCollectorProxy $group) {
        $group->post('/messages', [MessageController::class, 'send']);
        $group->get('/messages', [MessageController::class, 'list']);
        $group->get('/messages/{id}', [MessageController::class, 'get']);
        $group->get('/messages/{id}/file', [MessageController::class, 'file']);
        $group->delete('/messages/{id}', [MessageController::class, 'delete']);
    })->add(AuthMiddleware::class);
};