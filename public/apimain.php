<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

use Slim\Factory\AppFactory;
use DI\Container;
use App\Middleware\AuthMiddleware;
use App\Services\FileService;
use App\Controllers\MessageController;

require __DIR__ . '/../vendor/autoload.php';

// Создаём контейнер зависимостей (простой, можно заменить на PHP-DI позже)
$container = new Container();

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

// Загружаем переменные окружения из .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Устанавливаем соединение с БД в контейнер
$container->set('db', function () {
    $dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
});

// Настраиваем логгер (Monolog) и тоже кладём в контейнер
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$container->set('logger', function () {
    $logger = new Logger('app');
    $logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/app.log', Logger::DEBUG));
    return $logger;
});

$container->set(AuthMiddleware::class, function ($container) {
    return new \App\Middleware\AuthMiddleware($container->get('db'));
});

AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath('/device_api/public');

$app->get('/test', function ($request, $response) {
    $response->getBody()->write('test ok');
    return $response;
});

// Добавляем middleware для разбора тела запроса (JSON, form data)
$app->addBodyParsingMiddleware();

// Добавляем middleware для обработки ошибок (подробный режим для разработки)
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Регистрируем маршруты
(require __DIR__ . '/../src/routes.php')($app);

$app->run();