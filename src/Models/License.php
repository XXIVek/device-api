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

    /**
     * Найти лицензию по коду активации
     * @param string $code Код активации
     * @return array|false Данные лицензии или false
     */
    public function findByActivationCode($code)
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE activation_code = ? AND code_expires_at > NOW()');
        $stmt->execute([$code]);
        return $stmt->fetch();
    }

    /**
     * Установить код активации для лицензии
     * @param string $uuid UUID лицензии
     * @param string $code Код активации
     * @param int $expiresInMinutes Время жизни кода в минутах
     * @return bool Успешность установки
     */
    public function setActivationCode($uuid, $code, $expiresInMinutes = 30)
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInMinutes} minutes"));
        $sql = 'UPDATE licenses SET activation_code = ?, code_expires_at = ? WHERE uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$code, $expiresAt, $uuid]);
    }

    /**
     * Очистить код активации после использования
     * @param string $uuid UUID лицензии
     * @return bool Успешность очистки
     */
    public function clearActivationCode($uuid)
    {
        $sql = 'UPDATE licenses SET activation_code = NULL, code_expires_at = NULL WHERE uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$uuid]);
    }

    public function findById($id)
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE inn = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}