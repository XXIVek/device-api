<?php
namespace App\Models;

use PDO;

class Admin
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUsername($username)
    {
        $stmt = $this->db->prepare('SELECT * FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch();
    }

    public function verifyPassword($username, $password)
    {
        $admin = $this->findByUsername($username);
        if ($admin && password_verify($password, $admin['password_hash'])) {
            return $admin;
        }
        return false;
    }
}