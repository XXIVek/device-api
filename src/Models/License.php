<?php
namespace App\Models;

use PDO;
use Ramsey\Uuid\Uuid;

class License
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create($data, $organizationId)
    {
        $uuid = Uuid::uuid4()->toString();
        $sql = "INSERT INTO licenses (uuid, organization_id, code_from_key, license_number_from_key, 
                license_number_from_plain, inn, kpp, organization_name, city, version, delivery_number) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $uuid,
            $organizationId,
            $data['codeFromKey'],
            $data['licenseNumberFromKey'],
            $data['licenseNumberFromPlain'],
            $data['inn'],
            $data['kpp'],
            $data['organization'],
            $data['city'],
            $data['version'],
            $data['deliveryNumber'] ?? null
        ]);
        return $uuid;
    }

    public function findByLicenseNumber($licenseNumber)
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE license_number_from_plain = ?');
        $stmt->execute([$licenseNumber]);
        return $stmt->fetch();
    }

    public function findByUuid($uuid)
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch();
    }

    public function getByOrganizationId($organizationId)
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE organization_id = ?');
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll();
    }
    public function getAll()
    {
        $stmt = $this->db->query('SELECT * FROM licenses ORDER BY uuid');
        return $stmt->fetchAll();
    }

    public function findById($id)
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE inn = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}