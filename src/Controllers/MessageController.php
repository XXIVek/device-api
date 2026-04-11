<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Message;
use App\Models\Device;
use App\Services\FileService;
use PDO;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v;

class MessageController
{
    private $messageModel;
    private $deviceModel;
    private $fileService;
    private $logger;

    public function __construct(PDO $db, FileService $fileService, $logger)
    {
        $this->messageModel = new Message($db);
        $this->deviceModel = new Device($db);
        $this->fileService = $fileService;
        $this->logger = $logger;
    }

    public function send(Request $request, Response $response): Response
    {
        $senderUuid = $request->getAttribute('device_uuid');
        $parsedBody = $request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();

        if (!isset($parsedBody['recipient_uuid']) || !isset($parsedBody['message'])) {
            return $this->errorResponse($response, 'recipient_uuid and message are required', 400);
        }

        $recipientUuid = $parsedBody['recipient_uuid'];
        $subject = $parsedBody['subject'] ?? '';
        $messageJson = $parsedBody['message'];

        // Проверка существования получателя
        $recipient = $this->deviceModel->findByDeviceUuid($recipientUuid);
        if (!$recipient) {
            return $this->errorResponse($response, 'Recipient device not found', 404);
        }

        if (!v::json()->validate($messageJson)) {
            return $this->errorResponse($response, 'Message must be a valid JSON string', 400);
        }

        $filePath = null;
        if (isset($uploadedFiles['file']) && $uploadedFiles['file']->getError() === UPLOAD_ERR_OK) {
            $filePath = $this->fileService->saveUploadedFile($uploadedFiles['file'], $senderUuid);
            if (!$filePath) {
                $this->logger->warning('Failed to save uploaded file or validation failed', [
                    'sender' => $senderUuid,
                    'filename' => $uploadedFiles['file']->getClientFilename(),
                    'size' => $uploadedFiles['file']->getSize(),
                    'error_code' => $uploadedFiles['file']->getError()
                ]);
                return $this->errorResponse($response, 'Failed to save file or file validation failed. Check file type, size (max 10MB), and content.', 400);
            }
        }

        try {
            $messageId = $this->messageModel->create(
                $senderUuid,
                $recipientUuid,
                $subject,
                $messageJson,
                $filePath
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to create message', ['error' => $e->getMessage()]);
            return $this->errorResponse($response, 'Database error', 500);
        }

        $this->logger->info('Message sent', [
            'message_id' => $messageId,
            'from' => $senderUuid,
            'to' => $recipientUuid
        ]);

        $result = ['status' => 'ok', 'message_id' => $messageId];
        $response->getBody()->write(json_encode($result));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function list(Request $request, Response $response): Response
    {
        $recipientUuid = $request->getAttribute('device_uuid');
        $messages = $this->messageModel->getPendingForRecipient($recipientUuid);

        foreach ($messages as &$msg) {
            if ($msg['file_path']) {
                $msg['file_url'] = '/api/v1/messages/' . $msg['id'] . '/file';
            } else {
                $msg['file_url'] = null;
            }
        }

        $response->getBody()->write(json_encode($messages));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $recipientUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['id'];

        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }

        $msg = $this->messageModel->findForRecipient($messageIdBytes, $recipientUuid);
        if (!$msg) {
            return $this->errorResponse($response, 'Message not found', 404);
        }

        $msg['id'] = Uuid::fromBytes($msg['id'])->toString();
        if ($msg['file_path']) {
            $msg['file_url'] = '/api/v1/messages/' . $msg['id'] . '/file';
        }

        $response->getBody()->write(json_encode($msg));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function file(Request $request, Response $response, array $args): Response
    {
        $recipientUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['id'];

        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }

        $msg = $this->messageModel->findForRecipient($messageIdBytes, $recipientUuid);
        if (!$msg || !$msg['file_path']) {
            return $this->errorResponse($response, 'File not found', 404);
        }

        $fullPath = $this->fileService->getFullPath($msg['file_path']);
        if (!file_exists($fullPath)) {
            return $this->errorResponse($response, 'File not found on server', 404);
        }

        $response = $response->withHeader('Content-Type', mime_content_type($fullPath))
                             ->withHeader('Content-Disposition', 'attachment; filename="' . basename($fullPath) . '"');
        $response->getBody()->write(file_get_contents($fullPath));
        return $response;
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $recipientUuid = $request->getAttribute('device_uuid');
        $messageIdStr = $args['id'];

        try {
            $messageIdBytes = Uuid::fromString($messageIdStr)->getBytes();
        } catch (\Exception $e) {
            return $this->errorResponse($response, 'Invalid message ID', 400);
        }

        $filePath = $this->messageModel->deleteAndGetFilePath($messageIdBytes, $recipientUuid);
        if ($filePath === null) {
            return $this->errorResponse($response, 'Message not found', 404);
        }

        if ($filePath) {
            $this->fileService->deleteFile($filePath);
        }

        $this->logger->info('Message deleted', ['message_id' => $messageIdStr, 'recipient' => $recipientUuid]);

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