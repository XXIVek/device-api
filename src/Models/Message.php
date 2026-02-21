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

    // Создание нового сообщения
    public function create($senderId, $recipientId, $subject, $body, $filePath = null)
    {
        $id = Uuid::uuid4()->getBytes(); // генерируем бинарный UUID
        $sql = "INSERT INTO messages (id, sender_id, recipient_id, subject, body, file_path, status) 
                VALUES (?, ?, ?, ?, ?, ?, 'pending')";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id, $senderId, $recipientId, $subject, $body, $filePath]);
        // Возвращаем UUID в строковом виде
        return Uuid::fromBytes($id)->toString();
    }

    // Получить все ожидающие сообщения для получателя
    public function getPendingForRecipient($recipientId)
    {
        $stmt = $this->db->prepare('SELECT id, sender_id, subject, body, file_path, created_at 
                                     FROM messages 
                                     WHERE recipient_id = ? AND status = "pending" 
                                     ORDER BY created_at');
        $stmt->execute([$recipientId]);
        $rows = $stmt->fetchAll();
        // Конвертируем бинарный id в строку UUID
        foreach ($rows as &$row) {
            $row['id'] = Uuid::fromBytes($row['id'])->toString();
        }
        return $rows;
    }

    // Найти сообщение по ID (только если получатель совпадает)
    public function findForRecipient($messageIdBytes, $recipientId)
    {
        $stmt = $this->db->prepare('SELECT * FROM messages WHERE id = ? AND recipient_id = ?');
        $stmt->execute([$messageIdBytes, $recipientId]);
        return $stmt->fetch();
    }

    // Удалить сообщение (и вернуть путь к файлу)
    public function deleteAndGetFilePath($messageIdBytes, $recipientId)
    {
        $stmt = $this->db->prepare('SELECT file_path FROM messages WHERE id = ? AND recipient_id = ?');
        $stmt->execute([$messageIdBytes, $recipientId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        // Удаляем запись
        $stmt = $this->db->prepare('DELETE FROM messages WHERE id = ?');
        $stmt->execute([$messageIdBytes]);
        return $row['file_path'];
    }

    // Пометить как доставленное (если не хотим сразу удалять)
    public function markDelivered($messageIdBytes, $recipientId)
    {
        $stmt = $this->db->prepare('UPDATE messages SET status = "delivered", delivered_at = NOW() 
                                     WHERE id = ? AND recipient_id = ?');
        return $stmt->execute([$messageIdBytes, $recipientId]);
    }
}