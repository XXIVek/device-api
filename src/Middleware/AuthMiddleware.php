<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use App\Models\Device;

class AuthMiddleware
{
    private $deviceModel;

    public function __construct($db)
    {
        $this->deviceModel = new Device($db);
    }

    public function __invoke(Request $request, Handler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->unauthorized('Token not provided');
        }

        $deviceUuid = $matches[1];

        $device = $this->deviceModel->findByDeviceUuid($deviceUuid);
        if (!$device) {
            return $this->unauthorized('Invalid device token');
        }

        $request = $request->withAttribute('device_uuid', $device['device_uuid'])
                           ->withAttribute('license_uuid', $device['license_uuid']);

        return $handler->handle($request);
    }

    private function unauthorized($message): Response
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
    }
}