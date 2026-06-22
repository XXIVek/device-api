<?php
use Slim\Routing\RouteCollectorProxy;
use App\Controllers\MessageController;
use App\Controllers\LicenseController;
use App\Middleware\AuthMiddleware;
use App\Controllers\AdminController;
use App\Middleware\AdminAuthMiddleware;
use App\Controllers\DevicePairingController;

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
    
    // Группа защищённых маршрутов обмена (Exchange)
    $app->group('/api/v1/exchange', function (RouteCollectorProxy $group) {
        // Универсальный метод загрузки файла на сайт (1С или ТСД → Сайт)
        $group->post('/upload', [App\Controllers\ExchangeController::class, 'upload']);

        // Универсальный метод получения файлов с сайта (Сайт → 1С или ТСД)
        $group->get('/download', [App\Controllers\ExchangeController::class, 'download']);

        // Устаревшие методы (для обратной совместимости)
        $group->post('/incoming', [App\Controllers\ExchangeController::class, 'uploadFrom1C']);
        $group->post('/send-to-device', [App\Controllers\ExchangeController::class, 'sendToDevice']);
        $group->get('/incoming-for-device', [App\Controllers\ExchangeController::class, 'getIncomingForDevice']);
        $group->delete('/files/{id}', [App\Controllers\ExchangeController::class, 'deleteFile']);
        $group->post('/send', [App\Controllers\ExchangeController::class, 'sendToBackoffice']);
        $group->get('/incoming', [App\Controllers\ExchangeController::class, 'getIncomingFiles']);
        $group->get('/files/{id}', [App\Controllers\ExchangeController::class, 'getFile']);
        $group->put('/status/{id}', [App\Controllers\ExchangeController::class, 'updateStatus']);
        $group->get('/outgoing/{message_id}/status', [App\Controllers\ExchangeController::class, 'getOutgoingStatus']);
    })->add(AuthMiddleware::class);

    // Группа защищённых маршрутов для устройств
    $app->group('/api/v1/devices', function (RouteCollectorProxy $group) {
        // Получение статуса устройства
        $group->get('/status', [App\Controllers\ExchangeController::class, 'getDeviceStatus']);
        
        // Обновление статуса устройства (с поддержкой активации по коду без токена)
        $group->post('/status', [App\Controllers\ExchangeController::class, 'updateDeviceStatus']);
        
        // Генерация кода активации (вызывается из 1С с авторизацией по UUID)
        // UUID извлекается из заголовка Authorization: Bearer <uuid>
        $group->post('/generate-code', [App\Controllers\DevicePairingController::class, 'generateCode']);
    })->add(AuthMiddleware::class);

    // Публичный маршрут для активации устройства по коду (Android без токена)
    $app->post('/api/v1/devices/activate', [App\Controllers\DevicePairingController::class, 'activate']);

    // Группа защищённых маршрутов
    $app->group('/api/v1', function (RouteCollectorProxy $group) {

        $group->get('/licenses', [LicenseController::class, 'list']);
        $group->post('/messages', [MessageController::class, 'send']);
        $group->get('/messages', [MessageController::class, 'list']);
        $group->get('/messages/{id}', [MessageController::class, 'get']);
        $group->get('/messages/{id}/file', [MessageController::class, 'file']);
        $group->delete('/messages/{id}', [MessageController::class, 'delete']);
    })->add(AuthMiddleware::class);
};