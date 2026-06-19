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

    /**
     * Получить экземпляр PDO
     * @return PDO
     */
    public function getDb(): PDO
    {
        return $this->db;
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
     * Найти устройство по UUID
     * @param string $deviceUuid UUID устройства
     * @return array|false Данные устройства или false
     */
    public function findByUuid($deviceUuid)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE device_uuid = ?');
        $stmt->execute([$deviceUuid]);
        return $stmt->fetch();
    }

    /**
     * Привязать устройство к лицензии
     * @param string $deviceUuid UUID устройства
     * @param string $licenseUuid UUID лицензии
     * @param string|null $name Имя устройства
     * @return bool Успешность привязки
     */
    public function linkToLicense($deviceUuid, $licenseUuid, $name = null)
    {
        $sql = 'UPDATE devices SET license_uuid = ?, name = ? WHERE device_uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$licenseUuid, $name, $deviceUuid]);
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

    /**
     * Найти устройство по коду активации
     * @param string $code Код активации
     * @return array|false Данные устройства или false
     */
    public function findByActivationCode($code)
    {
        $stmt = $this->db->prepare('SELECT * FROM devices WHERE activation_code = ? AND code_expires_at > NOW()');
        $stmt->execute([$code]);
        return $stmt->fetch();
    }

    /**
     * Установить код активации для устройства
     * @param string $deviceUuid UUID устройства
     * @param string $code Код активации
     * @param int $expiresInMinutes Время жизни кода в минутах
     * @return bool Успешность установки
     */
    public function setActivationCode($deviceUuid, $code, $expiresInMinutes = 30)
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiresInMinutes} minutes"));
        $sql = 'UPDATE devices SET activation_code = ?, code_expires_at = ? WHERE device_uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$code, $expiresAt, $deviceUuid]);
    }

    /**
     * Очистить код активации после использования
     * @param string $deviceUuid UUID устройства
     * @return bool Успешность очистки
     */
    public function clearActivationCode($deviceUuid)
    {
        $sql = 'UPDATE devices SET activation_code = NULL, code_expires_at = NULL WHERE device_uuid = ?';
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$deviceUuid]);
    }
}