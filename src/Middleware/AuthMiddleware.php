<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use PDO;

class AuthMiddleware
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        // Получаем заголовок Authorization
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Token not provided']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $token = $matches[1];

        // Ищем устройство по токену в БД
        $stmt = $this->db->prepare('SELECT id FROM devices WHERE token = ?');
        $stmt->execute([$token]);
        $device = $stmt->fetch();

        if (!$device) {
            $response = new Response();
            $response->getBody()->write(json_encode(['error' => 'Invalid token']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Добавляем ID устройства в атрибуты запроса
        $request = $request->withAttribute('device_id', $device['id']);

        // Продолжаем обработку
        return $handler->handle($request);
    }
}