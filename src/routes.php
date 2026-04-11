<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\MessageController;
use App\Controllers\LicenseController;
use App\Middleware\AuthMiddleware;
use App\Controllers\AdminController;
use App\Middleware\AdminAuthMiddleware;

return function($app) {

    // Публичные маршруты админки (логин)
    $app->get('/admin/login', [AdminController::class, 'loginForm'])->setName('admin.login');
    $app->post('/admin/login', [AdminController::class, 'login']);

    // Группа защищённых маршрутов админки
    $app->group('/admin', function (RouteCollectorProxy $group) {
        $group->get('/logout', [AdminController::class, 'logout'])->setName('admin.logout');
        $group->get('/dashboard', [AdminController::class, 'dashboard'])->setName('admin.dashboard');
        $group->get('/organizations', [AdminController::class, 'organizations'])->setName('admin.organizations');
        $group->get('/organizations/{id}', [AdminController::class, 'organization'])->setName('admin.organization');
        $group->get('/licenses', [AdminController::class, 'licenses'])->setName('admin.licenses');
        $group->get('/licenses/{uuid}', [AdminController::class, 'license'])->setName('admin.license');
        $group->get('/devices', [AdminController::class, 'devices'])->setName('admin.devices');
        $group->get('/devices/{uuid}', [AdminController::class, 'device'])->setName('admin.device');
        $group->get('/messages', [AdminController::class, 'messages'])->setName('admin.messages');
        $group->get('/messages/{id}', [AdminController::class, 'message'])->setName('admin.message');
    })->add(AdminAuthMiddleware::class);
    
    // Публичный эндпоинт для регистрации лицензии
    $app->post('/api/v1/licenses', [LicenseController::class, 'register']);
    
    //Публичный эндпоинт список лицензий организации
    $app->get('/api/v1/licenses', [LicenseController::class, 'list']); 

    // Группа защищённых маршрутов обмена (Exchange)
    $app->group('/api/v1/exchange', function (RouteCollectorProxy $group) {
        // Отправка файла из торговой точки в backoffice
        $group->post('/send', [App\Controllers\ExchangeController::class, 'sendToBackoffice']);
        
        // Получение списка входящих файлов (для backoffice)
        $group->get('/incoming', [App\Controllers\ExchangeController::class, 'getIncomingFiles']);
        
        // Получение конкретного файла (для backoffice)
        $group->get('/files/{id}', [App\Controllers\ExchangeController::class, 'getFile']);
        
        // Обновление статуса обработки файла (для backoffice)
        $group->put('/status/{id}', [App\Controllers\ExchangeController::class, 'updateStatus']);
        
        // Получение статуса отправки файла (для торговой точки)
        $group->get('/outgoing/{message_id}/status', [App\Controllers\ExchangeController::class, 'getOutgoingStatus']);
    })->add(AuthMiddleware::class);

    // Группа защищённых маршрутов
    $app->group('/api/v1', function (RouteCollectorProxy $group) {

        $group->post('/messages', [MessageController::class, 'send']);
        $group->get('/messages', [MessageController::class, 'list']);
        $group->get('/messages/{id}', [MessageController::class, 'get']);
        $group->get('/messages/{id}/file', [MessageController::class, 'file']);
        $group->delete('/messages/{id}', [MessageController::class, 'delete']);
    })->add(AuthMiddleware::class);
};