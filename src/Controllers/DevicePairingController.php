<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\License;
use App\Models\Device;

class DevicePairingController
{
    private $licenseModel;
    private $deviceModel;
    private $logger;

    public function __construct($db, $logger)
    {
        $this->licenseModel = new License($db);
        $this->deviceModel = new Device($db);
        $this->logger = $logger;
    }

    /**
     * Генерация кода активации для устройства (вызывается из 1С)
     * POST /api/v1/devices/generate-code
     * Authorization: Bearer <device_uuid>
     * UUID извлекается из заголовка авторизации middleware-ем.
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function generateCode(Request $request, Response $response): Response
    {
        // Извлекаем UUID из атрибута, установленного AuthMiddleware
        $deviceUuid = $request->getAttribute('device_uuid');

        if (!$deviceUuid) {
            return $response->withJson([
                'success' => false,
                'error' => 'Unauthorized. Device UUID not found in token.'
            ], 401);
        }

        // Проверяем существование устройства
        $device = $this->deviceModel->findByUuid($deviceUuid);
        if (!$device) {
            return $response->withJson([
                'success' => false,
                'error' => 'Device not found'
            ], 404);
        }

        // Генерируем случайный код из 6 символов (цифры и заглавные буквы)
        $code = strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(5))), 0, 6));

        // Устанавливаем код активации (срок действия 30 минут)
        $success = $this->deviceModel->setActivationCode($deviceUuid, $code, 30);

        if (!$success) {
            return $response->withJson([
                'success' => false,
                'error' => 'Failed to generate activation code'
            ], 500);
        }

        $this->logger->info('Activation code generated', [
            'device_uuid' => $deviceUuid,
            'code' => $code
        ]);

        // Возвращаем код и QR-строку (для отображения в 1С)
        return $response->withJson([
            'success' => true,
            'activation_code' => $code,
            'qr_string' => "PAIR:$code", // Формат для QR-кода
            'expires_in' => 1800, // 30 минут в секундах
            'device_uuid' => $deviceUuid
        ], 200);
    }

    /**
     * Активация устройства по коду (вызывается из Android-приложения)
     * POST /api/v1/devices/activate
     * Без токена! Только код активации.
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function activate(Request $request, Response $response): Response
    {
        $parsedBody = $request->getParsedBody();
        $activationCode = $parsedBody['activation_code'] ?? null;

        // Валидация входных данных
        if (!$activationCode) {
            return $response->withJson([
                'success' => false,
                'error' => 'activation_code is required'
            ], 400);
        }

        // Ищем устройство по коду активации
        $device = $this->deviceModel->findByActivationCode($activationCode);
        if (!$device) {
            return $response->withJson([
                'success' => false,
                'error' => 'Invalid or expired activation code'
            ], 404);
        }

        $deviceUuid = $device['device_uuid'];

        $this->logger->info('Device activated by code', [
            'device_uuid' => $deviceUuid,
            'code' => $activationCode
        ]);

        // Возвращаем UUID устройства (код еще не сгорает, это произойдет при отправке статуса с paired=true)
        return $response->withJson([
            'success' => true,
            'device_uuid' => $deviceUuid,
            'message' => 'Use this UUID as Bearer token for subsequent requests'
        ], 200);
    }
}
