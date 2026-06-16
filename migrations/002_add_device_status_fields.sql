-- SQL миграция для добавления полей анализа состояния устройства
-- Выполнить на базе данных после деплоя новой версии

-- Добавляем поля для анализа состояния устройства в таблицу devices
ALTER TABLE devices 
ADD COLUMN IF NOT EXISTS pairing TINYINT(1) DEFAULT 0 COMMENT 'Сопряжение: 1 - истина, 0 - ложь' AFTER name,
ADD COLUMN IF NOT EXISTS konf TINYINT(2) DEFAULT 0 COMMENT 'Тип конфигурации: целое число от -99 до 99' AFTER pairing,
ADD COLUMN IF NOT EXISTS bd TINYINT(1) DEFAULT 0 COMMENT 'Состояние базы данных: целое число от -9 до 9' AFTER konf,
ADD COLUMN IF NOT EXISTS input TINYINT(1) DEFAULT 0 COMMENT 'Состояние входящих данных: целое число от -9 до 9' AFTER bd,
ADD COLUMN IF NOT EXISTS output TINYINT(1) DEFAULT 0 COMMENT 'Состояние исходящих данных: целое число от -9 до 9' AFTER input;

-- Добавляем индекс для фильтрации по состоянию устройств
CREATE INDEX IF NOT EXISTS idx_devices_pairing ON devices(pairing);
CREATE INDEX IF NOT EXISTS idx_devices_konf ON devices(konf);
CREATE INDEX IF NOT EXISTS idx_devices_bd ON devices(bd);
CREATE INDEX IF NOT EXISTS idx_devices_input ON devices(input);
CREATE INDEX IF NOT EXISTS idx_devices_output ON devices(output);
