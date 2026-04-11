<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Message;
use App\Models\Device;
use App\Models\License;
use App\Services\FileService;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v;

/**
 * Контроллер для обмена файлами между торговыми точками и backoffice
 * 
 * Алгоритм работы:
 * 1. Торговая точка регистрирует лицензию -> получает license_uuid и device_uuid
 * 2. Торговая точка отправляет файлы в backoffice (recipient_type=backoffice)
 * 3. Backoffice получает список файлов от торговых точек
 * 4. Backoffice запрашивает конкретные файлы для обработки
 */
class ExchangeController
{
    private $messageModel;
    private $deviceModel;
    private $licenseModel;
    private $fileService;
    private $logger;

    public function __construct(PDO $db, FileService $fileService, $logger)
    {
        $this->messageModel = new Message($db);
        $this->deviceModel = new Device($db);
        $this->licenseModel = new License($db);
        $this->fileService = $fileService;
        $this->logger = $logger;
    }

    /**
     * Отправка файла из торговой точки в backoffice
     * 
     * POST /api/v1/exchange/send
     * Authorization: Bearer <device_uuid>
     * Content-Type: multipart/form-data
     * 
     * Параметры:
     * - file: загружаемый файл
     * - message: JSON с метаданными (обязательно)
     * - subject: тема сообщения (опционально)
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function sendToBackoffice(Request $request, Response $response): Response
    {
        $senderDeviceUuid = $request->getAttribute('device_uuid');
        $senderLicenseUuid = $request->getAttribute('license_uuid');
        
        // Проверка активности лицензии отправителя
        $senderLicense = $this->licenseModel->findByUuid($senderLicenseUuid);
        if (!$senderLicense || !$this->isLicenseActive($senderLicense)) {
            $this->logger->warning('Attempt to send from inactive license', [
                'device_uuid' => $senderDeviceUuid,
                'license_uuid' => $senderLicenseUuid
            ]);
            return $this->errorResponse($response, 'Sender license is not active', 403, -101);
        }
        
        $uploadedFiles = $request->getUploadedFiles();
        $parsedBody = $request->getParsedBody();
        
        if (!isset($parsedBody['message'])) {
            return $this->errorResponse($response, 'message (JSON metadata) is required', 400);
        }
        
        $messageJson = $parsedBody['message'];
        $subject = $parsedBody['subject'] ?? 'Файл от торговой точки';
        
        // Валидация JSON
        if (!v::json()->validate($messageJson)) {
            return $this->errorResponse($response, 'Message must be a valid JSON string', 400);
        }
        
        // Проверка что получатель - backoffice
        // Backoffice использует специальную лицензию, найдем её по признаку
        $backofficeDevice = $this->getBackofficeDevice();
        if (!$backofficeDevice) {
            $this->logger->error('Backoffice device not found in database');
            return $this->errorResponse($response, 'Backoffice not configured', 500, -102);
        }
        
        $recipientUuid = $backofficeDevice['device_uuid'];
        
        // Сохранение файла
        $filePath = null;
        if (isset($uploadedFiles['file']) && $uploadedFiles['file']->getError() === UPLOAD_ERR_OK) {
            // Проверка расширения файла - только XML
            $originalFilename = $uploadedFiles['file']->getClientFilename();
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if ($extension !== 'xml') {
                return $this->errorResponse($response, 'Only XML files are allowed for exchange', 400, -103);
            }
            
            $filePath = $this->fileService->saveUploadedFile($uploadedFiles['file'], $senderDeviceUuid);
            if (!$filePath) {
                $this->logger->warning('Failed to save uploaded file', [
                    'sender' => $senderDeviceUuid,
                    'filename' => $uploadedFiles['file']->getClientFilename()
                ]);
                return $this->errorResponse($response, 'Failed to save file. File must be valid XML, max 10MB, no malicious content.', 400);
            }
        } else {
            return $this->errorResponse($response, 'File is required', 400);
        }
        
        // Создание сообщения
        try {
            $messageId = $this->messageModel->create(
                $senderDeviceUuid,
                $recipientUuid,
                $subject,
                $messageJson,
                $filePath
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to create message', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'Database error', 500);
        }
        
        $this->logger->info('File sent to backoffice', [
            'message_id' => $messageId,
            'from' => $senderDeviceUuid,
            'license' => $senderLicenseUuid,
            'filename' => $uploadedFiles['file']->getClientFilename()
        ]);
        
        $result = [
            'status' => 'ok',
            'message_id' => $messageId,
            'backoffice_device_uuid' => $recipientUuid
        ];
        
        $response->getBody()->write(json_encode($result));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получение списка файлов от торговых точек для backoffice
     * 
     * GET /api/v1/exchange/incoming
     * Authorization: Bearer <backoffice_device_uuid>
     * 
     * Параметры query:
     * - sender_uuid: фильтр по отправителю (опционально)
     * - limit: количество записей (по умолчанию 50)
     * - offset: смещение (по умолчанию 0)
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function getIncomingFiles(Request $request, Response $response): Response
    {
        $backofficeDeviceUuid = $request->getAttribute('device_uuid');
        
        // Проверка что это backoffice
        $backofficeDevice = $this->deviceModel->findByDeviceUuid($backofficeDeviceUuid);
        if (!$backofficeDevice || !$this->isBackofficeDevice($backofficeDevice)) {
            return $this->errorResponse($response, 'Access denied: backoffice only', 403);
        }
        
        $queryParams = $request->getQueryParams();
        $senderUuid = $queryParams['sender_uuid'] ?? null;
        $limit = min((int)($queryParams['limit'] ?? 50), 100);
        $offset = (int)($queryParams['offset'] ?? 0);
        
        $messages = $this->messageModel->getIncomingForBackoffice($backofficeDeviceUuid, $senderUuid, $limit, $offset);
        
        foreach ($messages as &$msg) {
            $msg['id'] = Uuid::fromBytes($msg['id'])->toString();
            $msg['sender_uuid'] = $msg['sender_uuid'];
            
            if ($msg['file_path']) {
                $msg['file_url'] = '/api/v1/exchange/files/' . $msg['id'];
                $msg['filename'] = basename($msg['file_path']);
            } else {
                $msg['file_url'] = null;
                $msg['filename'] = null;
            }
            
            // Декодируем JSON метаданных
            if (!empty($msg['body'])) {
                $decoded = json_decode($msg['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $msg['metadata'] = $decoded;
                }
            }
            
            // Удаляем сырое тело сообщения из ответа
            unset($msg['body']);
        }
        
        $response->getBody()->write(json_encode([
            'total' => count($messages),
            'limit' => $limit,
            'offset' => $offset,
            'items' => $messages
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получение конкретного файла backoffice
     * 
     * GET /api/v1/exchange/files/{message_id}
     * Authorization: Bearer <backoffice_device_uuid>
     * 
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function getFile(Request $request, Response $response, array $args): Response
    {
        $backofficeDeviceUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['id'];
        
        // Проверка что это backoffice
        $backofficeDevice = $this->deviceModel->findByDeviceUuid($backofficeDeviceUuid);
        if (!$backofficeDevice || !$this->isBackofficeDevice($backofficeDevice)) {
            return $this->errorResponse($response, 'Access denied: backoffice only', 403);
        }
        
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }
        
        $msg = $this->messageModel->findForRecipient($messageIdBytes, $backofficeDeviceUuid);
        if (!$msg || !$msg['file_path']) {
            return $this->errorResponse($response, 'File not found', 404);
        }
        
        $fullPath = $this->fileService->getFullPath($msg['file_path']);
        if (!file_exists($fullPath)) {
            return $this->errorResponse($response, 'File not found on server', 404);
        }
        
        // Помечаем сообщение как прочитанное/полученное
        $this->messageModel->markDelivered($messageIdBytes, $backofficeDeviceUuid);
        
        $response = $response->withHeader('Content-Type', mime_content_type($fullPath))
                             ->withHeader('Content-Disposition', 'attachment; filename="' . basename($fullPath) . '"')
                             ->withHeader('X-Message-ID', $messageIdStr)
                             ->withHeader('X-Sender-UUID', $msg['sender_uuid']);
        
        $response->getBody()->write(file_get_contents($fullPath));
        return $response;
    }

    /**
     * Статус обработки файла (для backoffice)
     * 
     * PUT /api/v1/exchange/status/{message_id}
     * Authorization: Bearer <backoffice_device_uuid>
     * 
     * Body:
     * {
     *   "status": "processed|rejected|error",
     *   "comment": "опциональный комментарий"
     * }
     * 
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function updateStatus(Request $request, Response $response, array $args): Response
    {
        $backofficeDeviceUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['id'];
        
        // Проверка что это backoffice
        $backofficeDevice = $this->deviceModel->findByDeviceUuid($backofficeDeviceUuid);
        if (!$backofficeDevice || !$this->isBackofficeDevice($backofficeDevice)) {
            return $this->errorResponse($response, 'Access denied: backoffice only', 403);
        }
        
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }
        
        $parsedBody = $request->getParsedBody();
        $status = $parsedBody['status'] ?? 'processed';
        $comment = $parsedBody['comment'] ?? '';
        
        if (!in_array($status, ['processed', 'rejected', 'error'])) {
            return $this->errorResponse($response, 'Invalid status value', 400);
        }
        
        // Обновление статуса сообщения
        $updated = $this->messageModel->updateExchangeStatus($messageIdBytes, $backofficeDeviceUuid, $status, $comment);
        
        if (!$updated) {
            return $this->errorResponse($response, 'Message not found', 404);
        }
        
        $this->logger->info('Exchange status updated', [
            'message_id' => $messageIdStr,
            'status' => $status,
            'comment' => $comment
        ]);
        
        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'message_id' => $messageIdStr,
            'exchange_status' => $status
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получение статуса отправки файла (для торговой точки)
     * 
     * GET /api/v1/exchange/outgoing/{message_id}/status
     * Authorization: Bearer <device_uuid>
     * 
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function getOutgoingStatus(Request $request, Response $response, array $args): Response
    {
        $senderDeviceUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['message_id'];
        
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }
        
        $msg = $this->messageModel->findForSender($messageIdBytes, $senderDeviceUuid);
        if (!$msg) {
            return $this->errorResponse($response, 'Message not found', 404);
        }
        
        // Проверка что получатель - backoffice
        $recipient = $this->deviceModel->findByDeviceUuid($msg['recipient_uuid']);
        if (!$recipient || !$this->isBackofficeDevice($recipient)) {
            return $this->errorResponse($response, 'Message not found', 404);
        }
        
        $responseData = [
            'message_id' => $messageIdStr,
            'status' => $msg['status'],
            'delivered_at' => $msg['delivered_at'],
            'exchange_status' => $msg['exchange_status'] ?? null,
            'exchange_comment' => $msg['exchange_comment'] ?? null
        ];
        
        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получить устройство backoffice
     * Ищем устройство с специальной лицензией backoffice
     */
    private function getBackofficeDevice(): ?array
    {
        // Вариант 1: Ищем по специальному INN backoffice
        $backofficeInn = 'BACKOFFICE001';
        $stmt = $this->deviceModel->getDb()->prepare(
            'SELECT d.* FROM devices d
             JOIN licenses l ON d.license_uuid = l.uuid
             JOIN organizations o ON l.organization_id = o.id
             WHERE o.inn = ?
             LIMIT 1'
        );
        $stmt->execute([$backofficeInn]);
        $device = $stmt->fetch();
        
        if ($device) {
            return $device;
        }
        
        // Вариант 2: Ищем первое устройство с флагом is_backoffice (если добавим такое поле)
        // Пока возвращаем null
        return null;
    }

    /**
     * Проверка является ли устройство backoffice
     */
    private function isBackofficeDevice(array $device): bool
    {
        $license = $this->licenseModel->findByUuid($device['license_uuid']);
        if (!$license) {
            return false;
        }
        
        // Проверяем INN организации на special backoffice marker
        $stmt = $this->deviceModel->getDb()->prepare(
            'SELECT o.inn FROM organizations o
             JOIN licenses l ON o.id = l.organization_id
             WHERE l.uuid = ?'
        );
        $stmt->execute([$device['license_uuid']]);
        $org = $stmt->fetch();
        
        return $org && $org['inn'] === 'BACKOFFICE001';
    }

    /**
     * Проверка активности лицензии
     */
    private function isLicenseActive(array $license): bool
    {
        // Простая проверка: лицензия существует и не помечена как отозванная
        // Можно расширить проверкой срока действия, статуса и т.д.
        
        // Если есть поле revoked или expired - проверить его
        if (isset($license['revoked']) && $license['revoked']) {
            return false;
        }
        
        if (isset($license['expired_at']) && strtotime($license['expired_at']) < time()) {
            return false;
        }
        
        return true;
    }

    private function errorResponse(Response $response, string $message, int $code, int $errorCode = null): Response
    {
        $data = ['error' => $message];
        if ($errorCode !== null) {
            $data['errorCode'] = $errorCode;
        }
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }
}
