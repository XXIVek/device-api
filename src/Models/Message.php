<?php
namespace App\Models;

use PDO;
use Ramsey\Uuid\Uuid;

class Message
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create($senderUuid, $recipientUuid, $subject, $body, $filePath = null)
    {
        $id = Uuid::uuid4()->getBytes();
        $sql = "INSERT INTO messages (id, sender_uuid, recipient_uuid, subject, body, file_path, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $senderUuid, $recipientUuid, $subject, $body, $filePath]);
        return Uuid::fromBytes($id)->toString();
    }

    public function getPendingForRecipient($recipientUuid)
    {
        $stmt = $this->db->prepare('SELECT id, sender_uuid, subject, body, file_path, created_at 
                                     FROM messages 
                                     WHERE recipient_uuid = ? AND status = "pending" 
                                     ORDER BY created_at');
        $stmt->execute([$recipientUuid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = Uuid::fromBytes($row['id'])->toString();
        }
        return $rows;
    }

    public function findForRecipient($messageIdBytes, $recipientUuid)
    {
        $stmt = $this->db->prepare('SELECT * FROM messages WHERE id = ? AND recipient_uuid = ?');
        $stmt->execute([$messageIdBytes, $recipientUuid]);
        return $stmt->fetch();
    }

    public function deleteAndGetFilePath($messageIdBytes, $recipientUuid)
    {
        $stmt = $this->db->prepare('SELECT file_path FROM messages WHERE id = ? AND recipient_uuid = ?');
        $stmt->execute([$messageIdBytes, $recipientUuid]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $stmt = $this->db->prepare('DELETE FROM messages WHERE id = ?');
        $stmt->execute([$messageIdBytes]);
        return $row['file_path'];
    }

    public function markDelivered($messageIdBytes, $recipientUuid)
    {
        $stmt = $this->db->prepare('UPDATE messages SET status = "delivered", delivered_at = NOW() 
                                     WHERE id = ? AND recipient_uuid = ?');
        return $stmt->execute([$messageIdBytes, $recipientUuid]);
    }

    public function getAll()
    {
        $stmt = $this->db->query('SELECT id, sender_uuid, recipient_uuid, subject, status, created_at, delivered_at FROM messages ORDER BY created_at DESC');
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = \Ramsey\Uuid\Uuid::fromBytes($row['id'])->toString();
        }
        return $rows;
    }   

    public function findById($idStr)
    {
        $idBytes = \Ramsey\Uuid\Uuid::fromString($idStr)->getBytes();
        $stmt = $this->db->prepare('SELECT * FROM messages WHERE id = ?');
        $stmt->execute([$idBytes]);
        $row = $stmt->fetch();
        if ($row) {
            $row['id'] = \Ramsey\Uuid\Uuid::fromBytes($row['id'])->toString();
        }
        return $row;
    }

    public function getForDevice($deviceUuid)
    {
        $stmt = $this->db->prepare('SELECT id, sender_uuid, recipient_uuid, subject, body, file_path, status, created_at, delivered_at FROM messages WHERE sender_uuid = ? OR recipient_uuid = ? ORDER BY created_at DESC');
        $stmt->execute([$deviceUuid, $deviceUuid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = \Ramsey\Uuid\Uuid::fromBytes($row['id'])->toString();
        }
        return $rows;
    }

    /**
     * Получить входящие сообщения для backoffice от торговых точек
     * @param string $backofficeDeviceUuid UUID устройства backoffice
     * @param string|null $senderUuid Фильтр по отправителю (опционально)
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @return array
     */
    public function getIncomingForBackoffice($backofficeDeviceUuid, $senderUuid = null, $limit = 50, $offset = 0)
    {
        if ($senderUuid) {
            $stmt = $this->db->prepare(
                'SELECT id, sender_uuid, subject, body, file_path, status, created_at, delivered_at, exchange_status, exchange_comment
                 FROM messages
                 WHERE recipient_uuid = ? AND sender_uuid = ?
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?'
            );
            $stmt->execute([$backofficeDeviceUuid, $senderUuid, $limit, $offset]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, sender_uuid, subject, body, file_path, status, created_at, delivered_at, exchange_status, exchange_comment
                 FROM messages
                 WHERE recipient_uuid = ?
                 ORDER BY created_at DESC
                 LIMIT ? OFFSET ?'
            );
            $stmt->execute([$backofficeDeviceUuid, $limit, $offset]);
        }
        
        return $stmt->fetchAll();
    }

    /**
     * Найти сообщение по ID для отправителя
     * @param string $messageIdBytes ID сообщения в бинарном формате
     * @param string $senderUuid UUID отправителя
     * @return array|null
     */
    public function findForSender($messageIdBytes, $senderUuid)
    {
        $stmt = $this->db->prepare(
            'SELECT id, sender_uuid, recipient_uuid, subject, body, file_path, status, created_at, delivered_at, exchange_status, exchange_comment
             FROM messages
             WHERE id = ? AND sender_uuid = ?'
        );
        $stmt->execute([$messageIdBytes, $senderUuid]);
        return $stmt->fetch();
    }

    /**
     * Обновить статус обработки файла для обмена
     * @param string $messageIdBytes ID сообщения в бинарном формате
     * @param string $backofficeDeviceUuid UUID backoffice
     * @param string $status Статус: processed|rejected|error
     * @param string $comment Комментарий
     * @return bool
     */
    public function updateExchangeStatus($messageIdBytes, $backofficeDeviceUuid, $status, $comment = '')
    {
        $stmt = $this->db->prepare(
            'UPDATE messages
             SET exchange_status = ?, exchange_comment = ?, updated_at = NOW()
             WHERE id = ? AND recipient_uuid = ?'
        );
        return $stmt->execute([$status, $comment, $messageIdBytes, $backofficeDeviceUuid]);
    }

    /**
     * Получить PDO соединение для использования в других классах
     * @return PDO
     */
    public function getDb()
    {
        return $this->db;
    }
}