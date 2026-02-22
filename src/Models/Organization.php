<?php
namespace App\Models;

use PDO;

class Organization
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByINN($inn)
    {
        $stmt = $this->db->prepare('SELECT * FROM organizations WHERE inn = ?');
        $stmt->execute([$inn]);
        return $stmt->fetch();
    }

    public function create($data)
    {
        $sql = "INSERT INTO organizations (inn, kpp, name, city) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$data['inn'], $data['kpp'], $data['organization'], $data['city']]);
        return $this->db->lastInsertId();
    }
}