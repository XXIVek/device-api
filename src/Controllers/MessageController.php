<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Message;
use App\Services\FileService;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v;

class MessageController
{
    private $db;
    private $messageModel;
    private $fileService;
    private $logger;

    public function __construct(PDO $db, FileService $fileService, $logger)
    {
        $this->db = $db;
        $this->fileService = $fileService;
        $this->logger = $logger;
        $this->messageModel = new Message($db);
    }

    /**
     * POST /api/messages
     * Ожидает multipart/form-data с полями:
     * - recipient_id (int)
     * - subject (string, optional)
     * - message (string, JSON)
     * - file (file, optional)
     */
    public function send(Request $request, Response $response): Response
    {
        $senderId = $request->getAttribute('device_id');
        $parsedBody = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        // Валидация
        if (!isset($parsedBody['recipient_id']) || !isset($parsedBody['message'])) {
            return $this->errorResponse($response, 'recipient_id and message are required', 400);
        }

        $recipientId = (int)$parsedBody['recipient_id'];
        $subject = $parsedBody['subject'] ?? '';
        $messageJson = $parsedBody['message'];

        // Проверяем, что получатель существует
        $stmt = $this->db->prepare('SELECT id FROM devices WHERE id = ?');
        $stmt->execute([$recipientId]);
        if (!$stmt->fetch()) {
            return $this->errorResponse($response, 'Recipient device not found', 404);
        }

        // Валидация JSON
        if (!v::json()->validate($messageJson)) {
            return $this->errorResponse($response, 'Message must be a valid JSON string', 400);
        }

        // Обработка файла (если есть)
        $filePath = null;
        if (isset($uploadedFiles['file']) && $uploadedFiles['file']->getError() === UPLOAD_ERR_OK) {
            $filePath = $this->fileService->saveUploadedFile($uploadedFiles['file'], $senderId);
            if (!$filePath) {
                $this->logger->warning('Failed to save uploaded file', ['sender' => $senderId]);
                // Можно продолжать без файла или вернуть ошибку – решите сами
                // Вернём ошибку, чтобы клиент знал
                return $this->errorResponse($response, 'Failed to save file', 500);
            }
        }

        // Сохраняем сообщение в БД
        try {
            $messageId = $this->messageModel->create(
                $senderId,
                $recipientId,
                $subject,
                $messageJson,
                $filePath
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to create message', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'Database error', 500);
        }

        $this->logger->info('Message sent', ['message_id' => $messageId, 'from' => $senderId, 'to' => $recipientId]);

        $result = ['status' => 'ok', 'message_id' => $messageId];
        $response->getBody()->write(json_encode($result));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/messages
     * Возвращает список ожидающих сообщений для текущего устройства (получателя)
     */
    public function list(Request $request, Response $response): Response
    {
        $recipientId = $request->getAttribute('device_id');
        $messages = $this->messageModel->getPendingForRecipient($recipientId);

        // Добавляем ссылки на файлы (если есть)
        foreach ($messages as &$msg) {
            if ($msg['file_path']) {
                $msg['file_url'] = '/api/messages/' . $msg['id'] . '/file';
            } else {
                $msg['file_url'] = null;
            }
        }

        $response->getBody()->write(json_encode($messages));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/messages/{id}
     * Возвращает метаданные сообщения (без файла), только если устройство - получатель
     */
    public function get(Request $request, Response $response, array $args): Response
    {
        $recipientId = $request->getAttribute('device_id');
        $messageIdStr = $args['id'];
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }

        $stmt = $this->db->prepare('SELECT id, sender_id, subject, body, file_path, created_at 
                                     FROM messages WHERE id = ? AND recipient_id = ?');
        $stmt->execute([$messageIdBytes, $recipientId]);
        $msg = $stmt->fetch();

        if (!$msg) {
            return $this->errorResponse($response, 'Message not found', 404);
        }

        // Конвертируем id в строку
        $msg['id'] = Uuid::fromBytes($msg['id'])->toString();
        if ($msg['file_path']) {
            $msg['file_url'] = '/api/messages/' . $msg['id'] . '/file';
        }

        $response->getBody()->write(json_encode($msg));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * GET /api/messages/{id}/file
     * Скачивает файл, если сообщение принадлежит текущему устройству
     */
    public function file(Request $request, Response $response, array $args): Response
    {
        $recipientId = $request->getAttribute('device_id');
        $messageIdStr = $args['id'];
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }

        $stmt = $this->db->prepare('SELECT file_path FROM messages WHERE id = ? AND recipient_id = ?');
        $stmt->execute([$messageIdBytes, $recipientId]);
        $row = $stmt->fetch();

        if (!$row || !$row['file_path']) {
            return $this->errorResponse($response, 'File not found', 404);
        }

        $fullPath = $this->fileService->getFullPath($row['file_path']);
        if (!file_exists($fullPath)) {
            return $this->errorResponse($response, 'File not found on server', 404);
        }

        // Отдаём файл
        $response = $response->withHeader('Content-Type', mime_content_type($fullPath))
                             ->withHeader('Content-Disposition', 'attachment; filename="' . basename($fullPath) . '"');
        $response->getBody()->write(file_get_contents($fullPath));
        return $response;
    }

    /**
     * DELETE /api/messages/{id}
     * Удаляет сообщение (и файл, если есть) после получения устройством
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $recipientId = $request->getAttribute('device_id');
        $messageIdStr = $args['id'];
        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }

        // Получаем путь к файлу и удаляем запись
        $filePath = $this->messageModel->deleteAndGetFilePath($messageIdBytes, $recipientId);
        if ($filePath === null) {
            return $this->errorResponse($response, 'Message not found', 404);
        }

        // Удаляем файл, если есть
        if ($filePath) {
            $this->fileService->deleteFile($filePath);
        }

        $this->logger->info('Message deleted', ['message_id' => $messageIdStr, 'recipient' => $recipientId]);

        $result = ['status' => 'ok'];
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function errorResponse(Response $response, string $message, int $code): Response
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response->withStatus($code)->withHeader('Content-Type', 'application/json');
    }
}