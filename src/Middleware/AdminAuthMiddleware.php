<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

class AdminAuthMiddleware
{
    public function __invoke(Request $request, Handler $handler): Response
    {
        if (!isset($_SESSION['admin_id'])) {
            // Сохраняем текущий URL для редиректа после логина
            $routeParser = RouteContext::fromRequest($request)->getRouteParser();
            $_SESSION['login_redirect'] = (string) $request->getUri();
            $response = new Response();
            return $response->withHeader('Location', '/device_api/public/admin/login')->withStatus(302);
        }
        return $handler->handle($request);
    }
}