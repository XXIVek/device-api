use App\Controllers\LicenseController;
use App\Controllers\MessageController;
use App\Middleware\AuthMiddleware;
use App\Services\FileService;

// ... после создания контейнера

$container->set(FileService::class, function () {
    return new FileService(__DIR__ . '/../storage/uploads');
});

$container->set(MessageController::class, function ($container) {
    return new MessageController(
        $container->get('db'),
        $container->get(FileService::class),
        $container->get('logger')
    );
});

$container->set(LicenseController::class, function ($container) {
    return new LicenseController(
        $container->get('db'),
        $container->get('logger')
    );
});

$container->set(AuthMiddleware::class, function ($container) {
    return new AuthMiddleware($container->get('db'));
});