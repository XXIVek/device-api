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
     * Универсальный метод загрузки файла на сайт (1С или ТСД → Сайт)
     * 
     * POST /api/v1/exchange/upload
     * Authorization: Bearer <device_uuid>
     * Content-Type: multipart/form-data
     * 
     * Параметры:
     * - file: загружаемый файл (XML, DBF) или JSON тело для массивов данных
     * - message: JSON с метаданными (обязательно для XML/DBF)
     * - subject: тема сообщения (опционально)
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function upload(Request $request, Response $response): Response
    {
        $senderDeviceUuid = $request->getAttribute('device_uuid');
        $senderLicenseUuid = $request->getAttribute('license_uuid');
        
        // Проверка устройства отправителя
        $senderDevice = $this->deviceModel->findByDeviceUuid($senderDeviceUuid);
        if (!$senderDevice) {
            return $this->errorResponse($response, 'Sender device not found', 404);
        }
        
        // Проверка активности лицензии отправителя
        $senderLicense = $this->licenseModel->findByUuid($senderLicenseUuid);
        if (!$senderLicense || !$this->isLicenseActive($senderLicense)) {
            $this->logger->warning('Attempt to upload from inactive license', [
                'device_uuid' => $senderDeviceUuid,
                'license_uuid' => $senderLicenseUuid
            ]);
            return $this->errorResponse($response, 'Sender license is not active', 403, -101);
        }
        
        $uploadedFiles = $request->getUploadedFiles();
        $parsedBody = $request->getParsedBody();
        
        // Определяем тип контента: файл (XML/DBF) или JSON данные
        $hasFile = isset($uploadedFiles['file']) && $uploadedFiles['file']->getError() === UPLOAD_ERR_OK;
        $hasJsonBody = !empty($parsedBody['data']) || (!empty($request->getBody()->getContents()) && $request->getHeaderLine('Content-Type') === 'application/json');
        
        if (!$hasFile && !$hasJsonBody) {
            return $this->errorResponse($response, 'File or JSON data is required', 400);
        }
        
        // Получаем backoffice как получателя
        $backofficeDevice = $this->getBackofficeDevice();
        if (!$backofficeDevice) {
            $this->logger->error('Backoffice device not found in database');
            return $this->errorResponse($response, 'Backoffice not configured', 500, -102);
        }
        
        $recipientUuid = $backofficeDevice['device_uuid'];
        
        // Обработка JSON данных (для 1С)
        if (!$hasFile && $hasJsonBody) {
            return $this->handleJsonUpload($request, $response, $senderDeviceUuid, $recipientUuid);
        }
        
        // Обработка файла (XML/DBF)
        $messageJson = $parsedBody['message'] ?? '{}';
        $subject = $parsedBody['subject'] ?? 'Файл от устройства';
        
        // Валидация JSON
        if (!v::json()->validate($messageJson)) {
            return $this->errorResponse($response, 'Message must be a valid JSON string', 400);
        }
        
        // Проверка расширения файла - только XML и DBF
        $originalFilename = $uploadedFiles['file']->getClientFilename();
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension !== 'xml' && $extension !== 'dbf') {
            return $this->errorResponse($response, 'Only XML and DBF files are allowed for exchange', 400, -103);
        }
        
        // Сохранение файла
        $fileResult = $this->fileService->saveUploadedFile($uploadedFiles['file'], $senderDeviceUuid);
        if (!$fileResult) {
            $this->logger->warning('Failed to save uploaded file', [
                'sender' => $senderDeviceUuid,
                'filename' => $uploadedFiles['file']->getClientFilename()
            ]);
            return $this->errorResponse($response, 'Failed to save file. File must be valid XML or DBF, max 10MB, no malicious content.', 400);
        }
        
        $filePath = $fileResult['path'];
        $originalFilenameStored = $fileResult['original_filename'];
        
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
        
        $this->logger->info('File uploaded to backoffice', [
            'message_id' => $messageId,
            'from' => $senderDeviceUuid,
            'license' => $senderLicenseUuid,
            'filename' => $originalFilenameStored
        ]);
        
        $result = [
            'status' => 'ok',
            'message_id' => $messageId,
            'backoffice_device_uuid' => $recipientUuid
        ];
        
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Обработка загрузки JSON данных (для 1С)
     */
    private function handleJsonUpload(Request $request, Response $response, string $senderDeviceUuid, string $recipientUuid): Response
    {
        // Получаем тело запроса (JSON)
        $body = $request->getBody()->getContents();
        if (empty($body)) {
            return $this->errorResponse($response, 'Request body is required', 400);
        }
        
        // Валидация JSON
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->errorResponse($response, 'Invalid JSON format', 400);
        }
        
        // Проверяем что это массив
        if (!is_array($data)) {
            return $this->errorResponse($response, 'Request body must be a JSON array', 400);
        }
        
        // Получаем имя файла из заголовка или генерируем
        $filename = $request->getHeaderLine('X-Filename');
        if (empty($filename)) {
            $filename = 'inventory_' . date('Y-m-d_H-i-s') . '.json';
        }
        
        // Сохранение JSON файла
        try {
            $fileResult = $this->saveJsonFile($body, $senderDeviceUuid, $filename);
            if (!$fileResult) {
                $this->logger->warning('Failed to save uploaded JSON file', [
                    'sender' => $senderDeviceUuid,
                    'filename' => $filename
                ]);
                return $this->errorResponse($response, 'Failed to save file', 400);
            }
            $filePath = $fileResult['path'];
            $originalFilenameStored = $fileResult['original_filename'];
        } catch (\Exception $e) {
            $this->logger->error('Failed to save JSON file', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'Failed to save file', 500);
        }
        
        // Создаём сообщение в базе данных
        $messageJson = json_encode([
            'type' => 'inventory_data',
            'items_count' => count($data),
            'uploaded_at' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        
        $subject = 'Данные инвентаризации';
        
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
        
        $this->logger->info('Data uploaded for TSD', [
            'message_id' => $messageId,
            'from' => $senderDeviceUuid,
            'to' => $recipientUuid,
            'filename' => $originalFilenameStored,
            'items_count' => count($data)
        ]);
        
        $result = [
            'status' => 'success',
            'filename' => $originalFilenameStored,
            'message_id' => Uuid::fromBytes($messageId)->toString(),
            'items_count' => count($data)
        ];
        
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Загрузка данных из 1С для передачи на ТСД (устаревший метод, используется upload)
     * 
     * POST /api/v1/exchange/incoming
     * Authorization: Bearer <device_uuid>
     * Content-Type: application/json
     * X-Filename: имя файла (опционально)
     * 
     * Тело запроса: плоский массив объектов с данными для ТСД
     * Пример: [{"Код": "123", "ТипШК": "EAN13", "Наименование": "Товар 1", "Цена": 100.00, "Колво": 10}, ...]
     * 
     * @deprecated Используйте POST /api/v1/exchange/upload
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function uploadFrom1C(Request $request, Response $response): Response
    {
        // Перенаправляем на универсальный метод
        return $this->upload($request, $response);
    }
    
    /**
     * Сохранить JSON файл от 1С
     * Возвращает массив с относительным путем и оригинальным именем файла или null в случае ошибки.
     */
    private function saveJsonFile(string $content, string $deviceId, string $filename): ?array
    {
        // Проверка размера файла
        $size = strlen($content);
        if ($size > 10 * 1024 * 1024) { // 10 MB
            return null;
        }
        
        // Проверка что контент валидный JSON
        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }
        
        // Санитизация имени файла
        $safeFilename = $this->sanitizeFilenameForJson($filename);
        
        // Генерируем уникальный префикс для избежания коллизий
        $uniquePrefix = Uuid::uuid4()->toString();
        $finalFilename = $uniquePrefix . '_' . $safeFilename;
        
        // Путь сохранения: storage/incoming/{device_id}/filename.json
        $path = $deviceId . '/' . $finalFilename;
        
        try {
            $fullPath = $this->fileService->getFullPath($path);
            $dir = dirname($fullPath);
            
            // Создаем директорию если не существует
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            
            // Записываем файл (перезаписываем если существует)
            file_put_contents($fullPath, $content);
            
            return [
                'path' => $path,
                'original_filename' => $safeFilename
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to write JSON file', ['error' => $e->getMessage()]);
            return null;
        }
    }
    
    /**
     * Санитизация имени JSON файла
     */
    private function sanitizeFilenameForJson(string $filename): string
    {
        // Удаляем опасные символы
        $filename = preg_replace('/[^a-zA-Z0-9_\\-.]/u', '_', $filename);
        // Убираем множественные подчеркивания
        $filename = preg_replace('/_+/', '_', $filename);
        // Гарантируем расширение .json
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'json') {
            $filename = pathinfo($filename, PATHINFO_FILENAME) . '.json';
        }
        // Ограничиваем длину имени файла
        if (strlen($filename) > 255) {
            $filename = substr($filename, 0, 250) . '.json';
        }
        return $filename;
    }

    /**
     * Отправка файла из backoffice в торговую точку
     * 
     * POST /api/v1/exchange/send-to-device
     * Authorization: Bearer <backoffice_device_uuid>
     * Content-Type: multipart/form-data
     * 
     * Параметры:
     * - recipient_device_uuid: UUID устройства получателя (торговой точки) (обязательно)
     * - file: загружаемый файл
     * - message: JSON с метаданными (обязательно)
     * - subject: тема сообщения (опционально)
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function sendToDevice(Request $request, Response $response): Response
    {
        $senderDeviceUuid = $request->getAttribute('device_uuid');
        
        // Проверка что устройство отправителя существует
        $senderDevice = $this->deviceModel->findByDeviceUuid($senderDeviceUuid);
        if (!$senderDevice) {
            return $this->errorResponse($response, 'Sender device not found', 404);
        }
        
        // Проверка активности лицензии отправителя
        $senderLicense = $this->licenseModel->findByUuid($senderDevice['license_uuid']);
        if (!$senderLicense || !$this->isLicenseActive($senderLicense)) {
            return $this->errorResponse($response, 'Sender license is not active', 403, -101);
        }
        
        $parsedBody = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        
        // Проверка recipient_device_uuid
        if (!isset($parsedBody['recipient_device_uuid'])) {
            return $this->errorResponse($response, 'recipient_device_uuid is required', 400);
        }
        
        $recipientDeviceUuid = $parsedBody['recipient_device_uuid'];
        
        // Проверка существования устройства получателя
        $recipientDevice = $this->deviceModel->findByDeviceUuid($recipientDeviceUuid);
        if (!$recipientDevice) {
            return $this->errorResponse($response, 'Recipient device not found', 404, -201);
        }
        
        // Проверка активности лицензии получателя
        $recipientLicense = $this->licenseModel->findByUuid($recipientDevice['license_uuid']);
        if (!$recipientLicense || !$this->isLicenseActive($recipientLicense)) {
            return $this->errorResponse($response, 'Recipient license is not active', 403, -202);
        }
        
        if (!isset($parsedBody['message'])) {
            return $this->errorResponse($response, 'message (JSON metadata) is required', 400);
        }
        
        $messageJson = $parsedBody['message'];
        $subject = $parsedBody['subject'] ?? 'Файл от backoffice';
        
        // Валидация JSON
        if (!v::json()->validate($messageJson)) {
            return $this->errorResponse($response, 'Message must be a valid JSON string', 400);
        }
        
        // Сохранение файла
        $filePath = null;
        $originalFilenameStored = null;
        if (isset($uploadedFiles['file']) && $uploadedFiles['file']->getError() === UPLOAD_ERR_OK) {
            // Проверка расширения файла - только XML и DBF
            $originalFilename = $uploadedFiles['file']->getClientFilename();
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if ($extension !== 'xml' && $extension !== 'dbf') {
                return $this->errorResponse($response, 'Only XML and DBF files are allowed for exchange', 400, -103);
            }
            
            $fileResult = $this->fileService->saveUploadedFile($uploadedFiles['file'], $senderDeviceUuid);
            if (!$fileResult) {
                $this->logger->warning('Failed to save uploaded file', [
                    'sender' => $senderDeviceUuid,
                    'filename' => $uploadedFiles['file']->getClientFilename()
                ]);
                return $this->errorResponse($response, 'Failed to save file. File must be valid XML or DBF, max 10MB, no malicious content.', 400);
            }
            // fileResult содержит ['path' => ..., 'original_filename' => ...]
            $filePath = $fileResult['path'];
            $originalFilenameStored = $fileResult['original_filename'];
        } else {
            return $this->errorResponse($response, 'File is required', 400);
        }
        
        // Создание сообщения
        try {
            $messageId = $this->messageModel->create(
                $senderDeviceUuid,
                $recipientDeviceUuid,
                $subject,
                $messageJson,
                $filePath
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to create message', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'Database error', 500);
        }
        
        $this->logger->info('File sent from sender to device', [
            'message_id' => $messageId,
            'from' => $senderDeviceUuid,
            'to' => $recipientDeviceUuid,
            'filename' => $originalFilenameStored
        ]);
        
        $result = [
            'status' => 'ok',
            'message_id' => $messageId,
            'recipient_device_uuid' => $recipientDeviceUuid
        ];
        
        $response->getBody()->write(json_encode($result, JSON_UNESCAPED_UNICODE));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Универсальный метод получения файлов с сайта (Сайт → 1С или ТСД)
     * 
     * GET /api/v1/exchange/download
     * Authorization: Bearer <device_uuid>
     * 
     * Параметры query:
     * - limit: количество записей (по умолчанию 50)
     * - offset: смещение (по умолчанию 0)
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function download(Request $request, Response $response): Response
    {
        $deviceUuid = $request->getAttribute('device_uuid');
        
        // Проверка устройства
        $device = $this->deviceModel->findByDeviceUuid($deviceUuid);
        if (!$device) {
            return $this->errorResponse($response, 'Device not found', 404);
        }
        
        $queryParams = $request->getQueryParams();
        $limit = min((int)($queryParams['limit'] ?? 50), 100);
        $offset = (int)($queryParams['offset'] ?? 0);
        
        // Определяем тип устройства и выбираем соответствующий метод
        if ($this->isBackofficeDevice($device)) {
            // Backoffice получает файлы от торговых точек
            $senderUuid = $queryParams['sender_uuid'] ?? null;
            $messages = $this->messageModel->getIncomingForBackoffice($deviceUuid, $senderUuid, $limit, $offset);
        } else {
            // ТСД/1С получает файлы от backoffice
            $messages = $this->messageModel->getIncomingForDevice($deviceUuid, $limit, $offset);
        }
        
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
        ], JSON_UNESCAPED_UNICODE));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Получение списка входящих файлов для торговой точки от backoffice (устаревший метод)
     * 
     * GET /api/v1/exchange/incoming-for-device
     * Authorization: Bearer <device_uuid>
     * 
     * @deprecated Используйте GET /api/v1/exchange/download
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function getIncomingForDevice(Request $request, Response $response): Response
    {
        return $this->download($request, $response);
    }

    /**
     * Удаление файла после успешного скачивания (для торговой точки)
     * 
     * DELETE /api/v1/exchange/files/{message_id}
     * Authorization: Bearer <device_uuid>
     * 
     * @param Request $request
     * @param Response $response
     * @param array $args
     * @return Response
     */
    public function deleteFile(Request $request, Response $response, array $args): Response
    {
        $deviceUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['id'];
        
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }
        
        // Получаем сообщение и проверяем права
        $msg = $this->messageModel->findForRecipient($messageIdBytes, $deviceUuid);
        if (!$msg) {
            return $this->errorResponse($response, 'Message not found', 404);
        }
        
        // Проверка что это не backoffice
        $device = $this->deviceModel->findByDeviceUuid($deviceUuid);
        if ($this->isBackofficeDevice($device)) {
            return $this->errorResponse($response, 'Backoffice should use other endpoint', 400);
        }
        
        // Удаляем сообщение и получаем путь к файлу
        $filePath = $this->messageModel->deleteAndGetFilePath($messageIdBytes, $deviceUuid);
        
        if ($filePath) {
            // Физическое удаление файла
            $fullPath = $this->fileService->getFullPath($filePath);
            if (file_exists($fullPath)) {
                unlink($fullPath);
                $this->logger->info('File deleted after download', [
                    'message_id' => $messageIdStr,
                    'device_uuid' => $deviceUuid,
                    'file_path' => $filePath
                ]);
            }
        }
        
        $this->logger->info('Message deleted', [
            'message_id' => $messageIdStr,
            'device_uuid' => $deviceUuid
        ]);
        
        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'message_id' => $messageIdStr,
            'deleted' => true
        ], JSON_UNESCAPED_UNICODE));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Отправка файла из торговой точки в backoffice (устаревший метод)
     * 
     * POST /api/v1/exchange/send
     * Authorization: Bearer <device_uuid>
     * Content-Type: multipart/form-data
     * 
     * @deprecated Используйте POST /api/v1/exchange/upload
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function sendToBackoffice(Request $request, Response $response): Response
    {
        // Перенаправляем на универсальный метод upload
        return $this->upload($request, $response);
    }

    /**
     * Получение списка файлов от торговых точек для backoffice (устаревший метод)
     * 
     * GET /api/v1/exchange/incoming
     * Authorization: Bearer <backoffice_device_uuid>
     * 
     * @deprecated Используйте GET /api/v1/exchange/download
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function getIncomingFiles(Request $request, Response $response): Response
    {
        // Перенаправляем на универсальный метод download
        return $this->download($request, $response);
    }

    /**
     * Получить входящие сообщения для Backoffice
     * 
     * GET /api/v1/exchange/incoming
     * Authorization: Bearer <backoffice_device_uuid>
     * Query params: sender_uuid (optional), limit, offset
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function getIncomingMessages(Request $request, Response $response): Response
    {
        $backofficeDeviceUuid = $request->getAttribute('device_uuid');
        
        // Проверка что устройство - backoffice
        if (!$this->deviceModel->isBackoffice($backofficeDeviceUuid)) {
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
        ], JSON_UNESCAPED_UNICODE));
        
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
        
        // Извлекаем оригинальное имя файла из пути (оно хранится после UUID_)
        $storedFilename = basename($fullPath);
        // Формат имени: {uuid}_{original_filename}, извлекаем original_filename
        $underscorePos = strpos($storedFilename, '_');
        if ($underscorePos !== false) {
            $originalFilename = substr($storedFilename, $underscorePos + 1);
        } else {
            $originalFilename = $storedFilename;
        }
        
        // Помечаем сообщение как прочитанное/полученное
        $this->messageModel->markDelivered($messageIdBytes, $backofficeDeviceUuid);
        
        $response = $response->withHeader('Content-Type', mime_content_type($fullPath))
                             ->withHeader('Content-Disposition', 'attachment; filename="' . $originalFilename . '"')
                             ->withHeader('X-Message-ID', $messageIdStr)
                             ->withHeader('X-Sender-UUID', $msg['sender_uuid'])
                             ->withHeader('X-Original-Filename', $originalFilename);
        
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
        ], JSON_UNESCAPED_UNICODE));
        
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

    /**
     * Получение статуса устройства
     * 
     * GET /api/v1/devices/status
     * Authorization: Bearer <device_uuid>
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function getDeviceStatus(Request $request, Response $response): Response
    {
        $deviceUuid = $request->getAttribute('device_uuid');
        
        $device = $this->deviceModel->findByDeviceUuid($deviceUuid);
        if (!$device) {
            return $this->errorResponse($response, 'Device not found', 404);
        }
        
        $status = $this->deviceModel->getStatus($deviceUuid);
        if (!$status) {
            return $this->errorResponse($response, 'Device status not found', 404);
        }
        
        $response->getBody()->write(json_encode([
            'device_uuid' => $deviceUuid,
            'status' => $status
        ], JSON_UNESCAPED_UNICODE));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Обновление статуса устройства
     * 
     * POST /api/v1/devices/status
     * Authorization: Bearer <device_uuid>
     * Content-Type: application/json
     * 
     * Body:
     * - pairing: bool (опционально, если true - код активации сгорает)
     * - konf: int (опционально)
     * - bd: int (опционально)
     * - input: int (опционально)
     * - output: int (опционально)
     * 
     * @param Request $request
     * @param Response $response
     * @return Response
     */
    public function updateDeviceStatus(Request $request, Response $response): Response
    {
        // Требуем токен авторизации (UUID устройства)
        $deviceUuid = $request->getAttribute('device_uuid');
        
        if (!$deviceUuid) {
            return $this->errorResponse($response, 'Unauthorized. Device UUID token required.', 401);
        }
        
        $device = $this->deviceModel->findByDeviceUuid($deviceUuid);
        if (!$device) {
            return $this->errorResponse($response, 'Device not found', 404);
        }
        
        $data = $request->getParsedBody();
        
        if (!is_array($data) || empty($data)) {
            return $this->errorResponse($response, 'Request body must contain status data', 400);
        }
        
        // Если pairing=true, очищаем код активации (код сгорает после успешного сопряжения)
        if (isset($data['pairing']) && $data['pairing'] == true) {
            $this->deviceModel->clearActivationCode($deviceUuid);
        }
        
        $success = $this->deviceModel->updateStatus($deviceUuid, $data);
        
        if (!$success) {
            return $this->errorResponse($response, 'Failed to update device status', 500);
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'ok',
            'device_uuid' => $deviceUuid,
            'paired' => isset($data['pairing']) && $data['pairing'] == true
        ], JSON_UNESCAPED_UNICODE));
        
        return $response->withHeader('Content-Type', 'application/json');
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
