# Документация API обмена файлами

## Обзор

Система обмена файлами между торговыми точками и Backoffice поддерживает два направления:

1. **Торговая точка → Backoffice** (отправка данных из торговой точки в центральный офис)
2. **Backoffice → Торговая точка** (рассылка данных из центрального офиса в торговые точки)

## Поддерживаемые форматы файлов

- **XML** - стандартный формат для обмена данными
- **DBF** - формат dBASE/FoxPro (версии 0x03, 0x30, 0x43, 0x8B, 0xF5 и др.)

Максимальный размер файла: **10MB**

### Сохранение имён файлов

Система сохраняет **оригинальные имена файлов** с добавлением уникального UUID-префикса для избежания коллизий. Например:
- Исходное имя: `order_2024.xml`
- Сохранённое имя: `550e8400-e29b-41d4-a716-446655440000_order_2024.xml`

При скачивании файла оригинальное имя автоматически восстанавливается через HTTP-заголовки:
- `Content-Disposition: attachment; filename="order_2024.xml"`
- `X-Original-Filename: order_2024.xml`

---

## Сценарий 1: Отправка файлов из Backoffice в торговую точку

### Шаг 1: Backoffice отправляет файл в торговую точку

**POST** `/api/v1/exchange/send-to-device`

**Заголовки:**
```
Authorization: Bearer <backoffice_device_uuid>
Content-Type: multipart/form-data
```

**Параметры:**
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| recipient_device_uuid | string | Да | UUID устройства получателя (торговой точки) |
| file | file | Да | Загружаемый файл (XML или DBF) |
| message | string | Да | JSON с метаданными |
| subject | string | Нет | Тема сообщения (по умолчанию: "Файл от backoffice") |

**Пример запроса (curl):**
```bash
curl -X POST https://api.example.com/api/v1/exchange/send-to-device \
  -H "Authorization: Bearer <backoffice_device_uuid>" \
  -F "recipient_device_uuid=<device_uuid>" \
  -F "file=@data.xml" \
  -F "message={\"type\":\"price_update\",\"version\":\"1.0\"}" \
  -F "subject=Обновление прайс-листа"
```

**Ответ при успехе (201):**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "recipient_device_uuid": "<device_uuid>"
}
```

**Коды ошибок:**
| Код | Сообщение | Описание |
|-----|-----------|----------|
| 400 | recipient_device_uuid is required | Не указан UUID получателя |
| 400 | message (JSON metadata) is required | Не указаны метаданные |
| 400 | Message must be a valid JSON string | Некорректный JSON |
| 400 | File is required | Файл не загружен |
| 400 | Only XML and DBF files are allowed | Неподдерживаемый формат файла |
| 403 | Access denied: backoffice only | Запрос не от backoffice |
| 403 | Recipient license is not active | Лицензия получателя неактивна |
| 404 | Recipient device not found | Устройство не найдено |

---

### Шаг 2: Торговая точка запрашивает список файлов

**GET** `/api/v1/exchange/incoming-for-device`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
```

**Параметры query:**
| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| limit | integer | 50 | Количество записей (макс. 100) |
| offset | integer | 0 | Смещение |

**Пример запроса:**
```bash
curl -X GET "https://api.example.com/api/v1/exchange/incoming-for-device?limit=10&offset=0" \
  -H "Authorization: Bearer <device_uuid>"
```

**Ответ (200):**
```json
{
  "total": 5,
  "limit": 10,
  "offset": 0,
  "items": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "sender_uuid": "<backoffice_device_uuid>",
      "subject": "Обновление прайс-листа",
      "metadata": {
        "type": "price_update",
        "version": "1.0"
      },
      "file_url": "/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000",
      "filename": "price_20240115.xml",
      "status": "pending",
      "created_at": "2024-01-15 10:30:00"
    }
  ]
}
```

---

### Шаг 3: Торговая точка скачивает файл

**GET** `/api/v1/exchange/files/{message_id}`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
```

**Пример запроса:**
```bash
curl -X GET "https://api.example.com/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Bearer <device_uuid>" \
  -o downloaded_file.xml
```

**Ответ при успехе (200):**
- Content-Type: MIME-тип файла (application/xml или application/x-dbf)
- Content-Disposition: attachment; filename="оригинальное_имя_файла.xml"
- X-Message-ID: ID сообщения
- X-Sender-UUID: UUID отправителя
- X-Original-Filename: оригинальное имя файла (сохраняется при загрузке)
- Тело: содержимое файла

Файл автоматически помечается как доставленный (delivered).

**Важно:** Сервер сохраняет оригинальное имя файла с уникальным префиксом для избежания коллизий. При скачивании оригинальное имя восстанавливается через заголовок `X-Original-Filename` и `Content-Disposition`.

---

### Шаг 4: Торговая точка удаляет файл после обработки (опционально)

**DELETE** `/api/v1/exchange/files/{message_id}`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
```

**Пример запроса:**
```bash
curl -X DELETE "https://api.example.com/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Bearer <device_uuid>"
```

**Ответ (200):**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "deleted": true
}
```

---

## Сценарий 2: Отправка файлов из торговой точки в Backoffice

### Шаг 1: Торговая точка отправляет файл

**POST** `/api/v1/exchange/send`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
Content-Type: multipart/form-data
```

**Параметры:**
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| file | file | Да | Загружаемый файл (XML или DBF) |
| message | string | Да | JSON с метаданными |
| subject | string | Нет | Тема сообщения (по умолчанию: "Файл от торговой точки") |

**Пример запроса:**
```bash
curl -X POST https://api.example.com/api/v1/exchange/send \
  -H "Authorization: Bearer <device_uuid>" \
  -F "file=@sales_data.dbf" \
  -F "message={\"type\":\"sales_report\",\"date\":\"2024-01-15\"}" \
  -F "subject=Отчёт о продажах"
```

**Ответ при успехе (201):**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "backoffice_device_uuid": "<backoffice_device_uuid>"
}
```

---

### Шаг 2: Backoffice получает список входящих файлов

**GET** `/api/v1/exchange/incoming`

**Заголовки:**
```
Authorization: Bearer <backoffice_device_uuid>
```

**Параметры query:**
| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| sender_uuid | string | - | Фильтр по отправителю |
| limit | integer | 50 | Количество записей (макс. 100) |
| offset | integer | 0 | Смещение |

**Ответ (200):**
```json
{
  "total": 10,
  "limit": 50,
  "offset": 0,
  "items": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "sender_uuid": "<device_uuid>",
      "subject": "Отчёт о продажах",
      "metadata": {
        "type": "sales_report",
        "date": "2024-01-15"
      },
      "file_url": "/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000",
      "filename": "sales_data.dbf",
      "status": "pending",
      "created_at": "2024-01-15 14:20:00"
    }
  ]
}
```

---

### Шаг 3: Backoffice скачивает файл

**GET** `/api/v1/exchange/files/{message_id}`

**Заголовки:**
```
Authorization: Bearer <backoffice_device_uuid>
```

**Ответ при успехе (200):**
- Содержимое файла
- Файл помечается как доставленный

---

### Шаг 4: Backoffice обновляет статус обработки

**PUT** `/api/v1/exchange/status/{message_id}`

**Заголовки:**
```
Authorization: Bearer <backoffice_device_uuid>
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "status": "processed",
  "comment": "Файл успешно обработан"
}
```

**Возможные значения status:**
- `processed` - файл успешно обработан
- `rejected` - файл отклонён
- `error` - ошибка обработки

**Ответ (200):**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "exchange_status": "processed"
}
```

---

### Шаг 5: Торговая точка проверяет статус обработки (опционально)

**GET** `/api/v1/exchange/outgoing/{message_id}/status`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
```

**Ответ (200):**
```json
{
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "status": "delivered",
  "delivered_at": "2024-01-15 14:25:00",
  "exchange_status": "processed",
  "exchange_comment": "Файл успешно обработан"
}
```

---

## Валидация DBF файлов

Система проверяет DBF файлы по заголовку:
- Первый байт определяет версию формата (0x03, 0x30, 0x43, 0x8B, 0xF5 и др.)
- Проверяется корректность структуры заголовка
- Проверяется отсутствие вредоносного содержимого

## Безопасность

- Все запросы требуют аутентификации через Bearer токен (device_uuid)
- Файлы проверяются на вирусы и вредоносное содержимое
- Максимальный размер файла ограничен (10MB)
- Разрешены только форматы XML и DBF

## Логирование

Все операции обмена логируются:
- Отправка файлов
- Скачивание файлов
- Удаление файлов
- Обновление статусов

Логи доступны в админ-панели и в файлах `/logs/app.log`.
