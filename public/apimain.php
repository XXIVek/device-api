<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

use Slim\Factory\AppFactory;
use DI\Container;
use App\Middleware\AuthMiddleware;
use App\Services\FileService;
use App\Controllers\MessageController;
use App\Controllers\LicenseController;
use App\Controllers\AdminController;

session_start(); // для работы сессий администратора

require __DIR__ . '/../vendor/autoload.php';

// Создаём контейнер зависимостей (простой, можно заменить на PHP-DI позже)
$container = new Container();

// Настройка Twig
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

$container->set(Twig::class, function () {
    return Twig::create(__DIR__ . '/../templates', [
        'cache' => false, // для разработки отключаем кэш
        'debug' => true,  // для использования dump и т.п.
    ]);
});

$container->set(AdminController::class, function ($container) {
    return new AdminController(
        $container->get(Twig::class),
        $container->get('db')
    );
});

// Загрузка файлов
$container->set(FileService::class, function () {
    return new FileService(__DIR__ . '/../storage/uploads');
});

// Управление лицензиями
$container->set(LicenseController::class, function ($container) {
    return new LicenseController(
        $container->get('db'),
        $container->get('logger')
    );
});

// Управление сообщениями
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

// Подключаем AuthMiddleware - промежуточное ПО для обработки обмена.
$container->set(AuthMiddleware::class, function ($container) {
    return new AuthMiddleware($container->get('db'));
});

// Фабрика приложения - устанавливаем контейнер
AppFactory::setContainer($container);
$app = AppFactory::create();
$app->setBasePath('/device_api/public');
// Добавляем middleware для Twig (будет добавлять переменные в ответ)
$app->add(TwigMiddleware::create($app, $container->get(Twig::class)));


// Тестовый маршрут для проверки routes
$app->get('/test/', function ($request, $response) {
    $response->getBody()->write('test ok');
    return $response;
});

// Добавляем middleware для разбора тела запроса (JSON, form data)
$app->addBodyParsingMiddleware();

// Добавляем middleware для обработки ошибок (подробный режим для разработки)
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// Регистрируем маршруты через routes.php
(require __DIR__ . '/../src/routes.php')($app);

//Заглущка для проверки маршрутов
/*
$routes = $app->getRouteCollector()->getRoutes();
echo "<h3>Registered routes:</h3><pre>";
foreach ($routes as $route) {
    echo $route->getPattern() . " (" . implode(',', $route->getMethods()) . ")\n";
}
echo "</pre>";
exit;
$uri = $_SERVER['REQUEST_URI'];
$basePath = $app->getBasePath();
echo "Base path: " . $basePath . "<br>";
echo "Request URI: " . $uri . "<br>";
if (strpos($uri, $basePath) === 0) {
    $relativePath = substr($uri, strlen($basePath));
    echo "Relative path: " . $relativePath . "<br>";
} else {
    echo "Request URI does not start with base path.<br>";
}
exit;
///////////////////////////
*/
$app->run();
