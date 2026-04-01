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
}