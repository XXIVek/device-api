-- SQL миграция для добавления полей обмена файлами
-- Выполнить на базе данных после деплоя новой версии

-- Добавляем поля для отслеживания статуса обработки файлов в обмене
ALTER TABLE messages 
ADD COLUMN IF NOT EXISTS exchange_status VARCHAR(20) DEFAULT NULL COMMENT 'Статус обработки: processed|rejected|error' AFTER delivered_at,
ADD COLUMN IF NOT EXISTS exchange_comment TEXT DEFAULT NULL COMMENT 'Комментарий к статусу обработки' AFTER exchange_status,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Добавляем индекс для ускорения выборки входящих сообщений для backoffice
CREATE INDEX IF NOT EXISTS idx_messages_recipient_status ON messages(recipient_uuid, status, created_at DESC);

-- Добавляем индекс для выборки по отправителю
CREATE INDEX IF NOT EXISTS idx_messages_sender_created ON messages(sender_uuid, created_at DESC);

-- Создаем организацию backoffice (если не существует)
-- INN BACKOFFICE001 - специальный маркер для центральной программы
INSERT INTO organizations (inn, kpp, name, city)
VALUES ('BACKOFFICE001', '', 'Центральная программа (Backoffice)', 'Москва')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Примечание: После выполнения этой миграции необходимо:
-- 1. Зарегистрировать лицензию для backoffice через POST /api/v1/licenses
-- 2. Сохранить полученный device_uuid для использования в заголовке Authorization
-- 3. Настроить backoffice на использование этого device_uuid
