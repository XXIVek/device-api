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
    public function getAll()
    {
        $stmt = $this->db->query('SELECT * FROM devices ORDER BY device_uuid');
        return $stmt->fetchAll();
    }

    public function findById($id)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE device_uuid = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function findByLicenseUuidAndName($licenseUuid, $name)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE license_uuid = ? AND name = ?');
        $stmt->execute([$licenseUuid, $name]);
        return $stmt->fetch();
    }

    /**
     * Обновить состояние устройства
     * @param string $deviceUuid UUID устройства
     * @param array $data Массив данных состояния
     * @return bool Успешность обновления
     */
    public function updateStatus($deviceUuid, $data)
    {
        $fields = [];
        $values = [];
        
        if (isset($data['pairing'])) {
            $fields[] = 'pairing = ?';
            $values[] = (int)$data['pairing'];
        }
        if (isset($data['konf'])) {
            $fields[] = 'konf = ?';
            $values[] = (int)$data['konf'];
        }
        if (isset($data['bd'])) {
            $fields[] = 'bd = ?';
            $values[] = (int)$data['bd'];
        }
        if (isset($data['input'])) {
            $fields[] = 'input = ?';
            $values[] = (int)$data['input'];
        }
        if (isset($data['output'])) {
            $fields[] = 'output = ?';
            $values[] = (int)$data['output'];
        }
        
        if (empty($fields)) {
            return false;
        }
        
        $values[] = $deviceUuid;
        $sql = 'UPDATE devices SET ' . implode(', ', $fields) . ' WHERE device_uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    /**
     * Получить состояние устройства
     * @param string $deviceUuid UUID устройства
     * @return array|false Массив состояния или false если устройство не найдено
     */
    public function getStatus($deviceUuid)
    {
        $stmt = $this->db->prepare('SELECT pairing, konf, bd, input, output FROM devices WHERE device_uuid = ?');
        $stmt->execute([$deviceUuid]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return false;
        }
        
        return [
            'pairing' => (bool)$result['pairing'],
            'konf' => (int)$result['konf'],
            'bd' => (int)$result['bd'],
            'input' => (int)$result['input'],
            'output' => (int)$result['output']
        ];
    }

    /**
     * Установить состояние устройства
     * @param string $deviceUuid UUID устройства
     * @param bool|int $pairing Сопряжение (true/false)
     * @param int $konf Тип конфигурации (-9..9)
     * @param int $bd Состояние базы данных (-9..9)
     * @param int $input Состояние входящих данных (-9..9)
     * @param int $output Состояние исходящих данных (-9..9)
     * @return bool Успешность обновления
     */
    public function setStatus($deviceUuid, $pairing, $konf, $bd, $input, $output)
    {
        $sql = 'UPDATE devices SET pairing = ?, konf = ?, bd = ?, input = ?, output = ? WHERE device_uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            (int)$pairing,
            (int)$konf,
            (int)$bd,
            (int)$input,
            (int)$output,
            $deviceUuid
        ]);
    }
}