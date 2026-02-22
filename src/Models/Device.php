<?php
namespace App\Models;

use PDO;
use Ramsey\Uuid\Uuid;

class Device
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create($licenseUuid, $name = null)
    {
        $deviceUuid = Uuid::uuid4()->toString();
        $sql = "INSERT INTO devices (device_uuid, license_uuid, name) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$deviceUuid, $licenseUuid, $name]);
        return $deviceUuid;
    }

    public function findByDeviceUuid($deviceUuid)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE device_uuid = ?');
        $stmt->execute([$deviceUuid]);
        return $stmt->fetch();
    }

    public function findByLicenseUuid($licenseUuid)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE license_uuid = ?');
        $stmt->execute([$licenseUuid]);
        return $stmt->fetchAll();
    }
}