-- SQL миграция для добавления поля activation_code в таблицу devices
-- Выполнить на базе данных после деплоя новой версии

-- Добавляем поле для кода активации устройства
ALTER TABLE devices 
ADD COLUMN IF NOT EXISTS activation_code VARCHAR(10) DEFAULT NULL COMMENT 'Код активации (6 символов)' AFTER device_uuid,
ADD COLUMN IF NOT EXISTS code_expires_at DATETIME DEFAULT NULL COMMENT 'Время истечения кода активации' AFTER activation_code;

-- Добавляем индекс для быстрого поиска по коду активации
CREATE INDEX IF NOT EXISTS idx_devices_activation_code ON devices(activation_code);

-- Примечание: 
-- 1. Код генерируется при запросе от 1С через POST /api/v1/devices/{uuid}/generate-code
-- 2. Код сгорает после успешной активации Android (когда paired устанавливается в true)
-- 3. Android отправляет код на активацию без токена, получает UUID, затем использует его для статуса
