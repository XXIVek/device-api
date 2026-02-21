<?php
namespace App\Models;

use PDO;

class Device
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByToken($token)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch();
    }
}