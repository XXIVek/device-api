# Сценарий обмена 1С — Web сервер — ТСД

## Обзор

Данный документ описывает сценарий обмена данными между конфигурацией 1С (Конф1С) и терминалом сбора данных (ТСД) через Web сервер. Обмен осуществляется в формате JSON и контролируется через статусы устройства.

## Участники обмена

- **1С (Конф1С)** — конфигурация, которая отправляет задания на ТСД и получает результаты
- **Web сервер** — промежуточное звено, хранит файлы и сообщения, управляет жизненным циклом обмена
- **ТСД** — терминал сбора данных, выполняет задания путём сканирования штрихкодов

## Цикл обмена 1С ↔ ТСД

### Прямой обмен (1С → ТСД):
1. **1С загружает файл** → файл сохраняется с оригинальным именем, создаётся сообщение со статусом `pending`
2. **При замещении** — файл с тем же именем перезаписывается, сообщение обновляется
3. **ТСД запрашивает входящие** → видит только `pending` сообщения (не загруженные)
4. **ТСД скачивает файл** (GET) → файл **НЕ удаляется** — ТСД работает с файлом

### Обратный обмен (ТСД → 1С):
1. **ТСД загружает файл с результатами** → файл сохраняется, создаётся сообщение
2. **При замещении** — файл с тем же именем перезаписывается, сообщение обновляется
3. **1С запрашивает входящие** → видит все сообщения (backoffice получает всё)
4. **1С скачивает файл** → помечается как delivered

---

## Ключевые принципы

### 1. Замещение файлов
- Файлы хранятся с **оригинальным именем** (без UUID-префикса)
- При загрузке файла с тем же именем — старый файл **перезаписывается**
- Сообщение с тем же `file_path` и `sender_uuid` **обновляется**

### 2. Фильтрация входящих сообщений
- **Для ТСД**: `GET /incoming-for-device` возвращает **только `pending`** сообщения
- **Для Backoffice**: `GET /incoming` возвращает **все** сообщения

### 3. Управление удалением файлов
- **Для ТСД**: файл задания **НЕ удаляется** при скачивании
- ТСД удаляет файл задания вручную через `DELETE /api/v1/exchange/files/{id}` **после** загрузки результатов в 1С
- **Для backoffice**: файл результатов **НЕ удаляется** при скачивании
- backoffice помечает файл статусом processed/rejected через `PUT /api/v1/exchange/status/{id}`

### 4. Структура хранения
```
storage/uploads/
└── {device_uuid}/
    ├── {original_filename}.json
    ├── {original_filename}.xml
    └── ...
```

---

## Архитектура взаимодействия

1. **1С готовит информацию** для загрузки в ТСД в формате JSON
2. **Оператор ТСД загружает** эту информацию и выполняет задание путём чтения штрихкодов
3. **По окончании выполнения** оператор ТСД передаёт данные в формате JSON обратно в 1С
4. **1С формирует** по полученным данные необходимые документы
5. **Взаимодействие контролируется** с помощью статусов устройства

---

## Требования к системе

### Лицензии и устройства

- **1 лицензия = 1 устройство** (привязка нескольких устройств к одной лицензии не предусмотрена)
- Для сценария 1С-ТСД используется **одно устройство** с общим device_uuid

### Поддерживаемые форматы файлов

- **JSON** — основной формат для обмена в сценарии 1С-ТСД
- **XML** — для сценария 1С-1С
- **DBF** — для сценария 1С-1С
- Максимальный размер файла: **10MB**

### Статусы устройств

| Поле | Тип | Описание |
|------|-----|----------|
| `pairing` | boolean | Состояние сопряжения |
| `konf` | integer (-9..9) | Тип конфигурации |
| `bd` | integer (-9..9) | Состояние базы данных |
| `input` | integer (-9..9) | Состояние входящих данных |
| `output` | integer (-9..9) | Состояние исходящих данных |

### Безопасность

- Аутентификация через **Bearer-токен** (device_uuid)

### Производительность

- Ожидаемое количество одновременных устройств: **до 100**
- Ожидаемый объём файлов в день: **~10 файлов на одно устройство** (для ТСД)
- Требования к времени отклика API: **до 5 секунд**

---

## Структура таблицы `messages`

### Описание колонок

| # | Имя | Тип данных | Длина | По умолчанию | Описание |
|---|-----|------------|-------|--------------|----------|
| 1 | `id` | `BINARY` | 16 | — | UUID в бинарном формате |
| 2 | `sender_uuid` | `CHAR` | 36 | NULL | UUID устройства-отправителя |
| 3 | `recipient_uuid` | `CHAR` | 36 | NULL | UUID устройства-получателя |
| 4 | `subject` | `VARCHAR` | 255 | NULL | Тема сообщения |
| 5 | `body` | `LONGTEXT` | — | NULL | Тело сообщения (JSON-данные) |
| 6 | `file_path` | `VARCHAR` | 512 | NULL | Путь к прикреплённому файлу |
| 7 | `status` | `ENUM` | — | `'pending'` | Статус: `pending`, `delivered` |
| 8 | `created_at` | `TIMESTAMP` | — | current_timestamp | Время создания |
| 9 | `delivered_at` | `TIMESTAMP` | — | NULL | Время доставки |
| 10 | `exchange_status` | `VARCHAR` | 20 | NULL | Статус обработки: processed/rejected/error |
| 11 | `exchange_comment` | `TEXT` | — | NULL | Комментарий к статусу |
| 12 | `updated_at` | `TIMESTAMP` | — | NULL | Время последнего обновления |

---

## Сценарий A: Регистрация и сопряжение устройства

### Шаг 1: Регистрация устройства в 1С

1С создаёт устройство и получает device_uuid.

**POST** `/api/v1/devices/register`

**Тело запроса:**
```json
{
  "license_uuid": "<license_uuid>",
  "device_name": "ТСД-001",
  "device_type": "tsd"
}
```

**Ответ:**
```json
{
  "status": "ok",
  "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "license_uuid": "<license_uuid>"
}
```

### Шаг 2: Сопряжение устройства

**POST** `/api/v1/devices/pair`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
```

**Ответ:**
```json
{
  "status": "ok",
  "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "paired": true
}
```

---

## Сценарий B: Прямой обмен 1С → Сайт

### Шаг 1: 1С отправляет файл задания на Сайт

**POST** `/api/v1/exchange/upload`

**Заголовки:**
```
Authorization: Bearer <device_uuid_1C>
Content-Type: multipart/form-data
```

**Параметры:**
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| file | file | Да | Загружаемый файл (JSON, XML, DBF) |
| message | string | Да | JSON с метаданными |
| subject | string | Нет | Тема сообщения |

**Пример запроса (curl):**
```bash
curl -X POST https://YOUR_SERVER/device_api/public/api/v1/exchange/upload \
  -H "Authorization: Bearer <device_uuid_1C>" \
  -F "file=@task.json" \
  -F 'message={"type":"inventory_task","date":"2024-01-15"}' \
  -F "subject=Задание на инвентаризацию"
```

**Ответ при успехе (201):**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "backoffice_device_uuid": "<device_uuid_1C>"
}
```

### Замещение файла при повторной отправке

Если 1С отправляет файл с тем же именем ещё раз:
- Файл **перезаписывается** на диске
- Сообщение **обновляется** (sender_uuid, recipient_uuid, subject, body)

---

## Сценарий B1: Сайт → ТСД (получение заданий)

### Шаг 1: ТСД запрашивает список pending файлов

**GET** `/api/v1/exchange/download`

**Заголовки:**
```
Authorization: Bearer <device_uuid_ТСД>
```

**Параметры query:**
| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| limit | integer | 50 | Количество записей (макс. 100) |
| offset | integer | 0 | Смещение |

**Важно:** Возвращаются **только `pending`** сообщения (файлы, которые ТСД ещё не скачал).

**Файл НЕ удаляется** при получении списка — ТСД может скачать его позже.

**Пример запроса (curl):**
```bash
curl -v -H "Authorization: Bearer <device_uuid_ТСД>" \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/download?limit=50&offset=0"
```

**Ответ:**
```json
{
  "total": 1,
  "limit": 50,
  "offset": 0,
  "items": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "sender_uuid": "<device_uuid_1C>",
      "subject": "Задание на инвентаризацию",
      "metadata": {
        "type": "inventory_task",
        "date": "2024-01-15"
      },
      "file_url": "/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000",
      "filename": "task.json",
      "status": "pending",
      "created_at": "2024-01-15 10:30:00"
    }
  ]
}
```

### Шаг 2: ТСД скачивает файл задания

**GET** `/api/v1/exchange/files/{message_id}`

**Заголовки:**
```
Authorization: Bearer <device_uuid_ТСД>
```

**Пример запроса (curl):**
```bash
curl -v -H "Authorization: Bearer <device_uuid_ТСД>" \
  -o task.json \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000"
```

**Ответ при успехе (200):**
- Content-Type: MIME-тип файла (например, `application/json`)
- Content-Disposition: attachment; filename="task.json"
- X-Message-ID: ID сообщения
- X-Sender-UUID: UUID отправителя
- X-Original-Filename: оригинальное имя файла
- Тело: содержимое файла

**Важно:** Файл **НЕ удаляется** после скачивания — ТСД может работать с ним.

### Шаг 3: ТСД обрабатывает файл (сканирует штрихкоды)

Оператор ТСД выполняет задание — сканирует штрихкоды из полученного файла.

### Шаг 4: ТСД загружает файл с результатами

**POST** `/api/v1/exchange/upload` (аналогично сценарию B2)

### Шаг 5: ТСД удаляет файл задания (после загрузки результатов)

После успешной загрузки результатов в 1С, ТСД удаляет файл задания:

**DELETE** `/api/v1/exchange/files/{message_id}`

**Заголовки:**
```
Authorization: Bearer <device_uuid_ТСД>
```

**Пример запроса (curl):**
```bash
curl -v -X DELETE -H "Authorization: Bearer <device_uuid_ТСД>" \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000"
```

**Ответ:**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "deleted": true
}
```

**Важно:** Файл удаляется только **после** успешной загрузки результатов в 1С.

---

## Сценарий B2: ТСД → Сайт (отправка результатов)

### Шаг 1: ТСД отправляет файл с результатами

**POST** `/api/v1/exchange/upload`

**Заголовки:**
```
Authorization: Bearer <device_uuid_ТСД>
Content-Type: multipart/form-data
```

**Параметры:**
| Параметр | Тип | Обязательный | Описание |
|----------|-----|--------------|----------|
| file | file | Да | Загружаемый файл (JSON) |
| message | string | Да | JSON с метаданными |
| subject | string | Нет | Тема сообщения |

**Пример запроса (curl):**
```bash
curl -X POST https://YOUR_SERVER/device_api/public/api/v1/exchange/upload \
  -H "Authorization: Bearer <device_uuid_ТСД>" \
  -F "file=@result.json" \
  -F 'message={"type":"inventory_result","task_id":"12345"}' \
  -F "subject=Результаты инвентаризации"
```

**Ответ при успехе (201):**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "backoffice_device_uuid": "<device_uuid_1C>"
}
```

### Замещение файла при повторной отправке

Если ТСД отправляет файл с тем же именем ещё раз:
- Файл **перезаписывается** на диске
- Сообщение **обновляется** (sender_uuid, recipient_uuid, subject, body)

---

## Сценарий C: 1С получает результаты со Сайта

### Шаг 1: 1С запрашивает список входящих файлов

**GET** `/api/v1/exchange/download`

**Заголовки:**
```
Authorization: Bearer <device_uuid_1C>
```

**Параметры query:**
| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| sender_uuid | string | - | Фильтр по отправителю (UUID ТСД) |
| limit | integer | 50 | Количество записей (макс. 100) |
| offset | integer | 0 | Смещение |

**Важно:** Backoffice (1С) получает **все** сообщения (включая delivered).

**Пример запроса (curl):**
```bash
curl -v -H "Authorization: Bearer <device_uuid_1C>" \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/download?sender_uuid=<device_uuid_ТСД>&limit=50&offset=0"
```

**Ответ:**
```json
{
  "total": 1,
  "limit": 50,
  "offset": 0,
  "items": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "sender_uuid": "<device_uuid_ТСД>",
      "subject": "Результаты инвентаризации",
      "metadata": {
        "type": "inventory_result",
        "task_id": "12345"
      },
      "file_url": "/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000",
      "filename": "result.json",
      "status": "pending",
      "created_at": "2024-01-15 14:20:00"
    }
  ]
}
```

### Шаг 2: 1С скачивает файл с результатами

**GET** `/api/v1/exchange/files/{message_id}`

**Заголовки:**
```
Authorization: Bearer <device_uuid_1C>
```

**Пример запроса (curl):**
```bash
curl -v -H "Authorization: Bearer <device_uuid_1C>" \
  -o result.json \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000"
```

**Ответ при успехе (200):**
- Содержимое файла
- Файл **НЕ удаляется** — backoffice управляет жизненным циклом файлов

### Шаг 3: 1С обновляет статус обработки файла (опционально)

**PUT** `/api/v1/exchange/status/{message_id}`

**Заголовки:**
```
Authorization: Bearer <device_uuid_1C>
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "status": "processed",
  "comment": "Документы созданы"
}
```

**Ответ:**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440000",
  "exchange_status": "processed"
}
```

---

## Управление статусами устройства

### Получение статуса устройства

**GET** `/api/v1/devices/status`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
```

**Ответ:**
```json
{
  "device_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "status": {
    "pairing": true,
    "konf": 5,
    "bd": 3,
    "input": 2,
    "output": 4
  }
}
```

### Обновление статуса устройства

**POST** `/api/v1/devices/status`

**Заголовки:**
```
Authorization: Bearer <device_uuid>
Content-Type: application/json
```

**Тело запроса:**
```json
{
  "pairing": true,
  "konf": 5,
  "bd": 3,
  "input": 2,
  "output": 4
}
```

---

## Коды ошибок

| Код | Сообщение | Описание |
|-----|-----------|----------|
| 400 | recipient_device_uuid is required | Не указан UUID получателя |
| 400 | message (JSON metadata) is required | Не указаны метаданные |
| 400 | Message must be a valid JSON string | Некорректный JSON |
| 400 | File is required | Файл не загружен |
| 400 | Only XML, DBF and JSON files are allowed | Неподдерживаемый формат файла |
| 403 | Access denied: backoffice only | Запрос не от backoffice |
| 403 | Recipient license is not active | Лицензия получателя неактивна |
| 404 | Device not found | Устройство не найдено |
| 404 | Message not found | Сообщение не найдено |
| 500 | Internal server error | Внутренняя ошибка сервера |

---

## Логирование

Все операции обмена логируются в `/logs/app.log`:
- Отправка файлов
- Скачивание файлов
- Удаление файлов (по DELETE запросу)
- Обновление статусов устройств

---

## Примечания

1. **Формат JSON** — основной формат для сценария 1С-ТСД
2. **Замещение файлов** — файлы с одинаковыми именами перезаписываются, сообщения обновляются
3. **Удаление файлов** — файл задания удаляется ТСД вручную через DELETE после загрузки результатов
4. **Фильтрация pending** — ТСД видит только не скачанные файлы
5. **Масштабируемость** — система рассчитана на работу с до 100 одновременными устройствами

---

## Технические детали реализации

### Исправление LIMIT/OFFSET в SQL-запросах

**Проблема:** PDO оборачивает параметризированные `LIMIT ? OFFSET ?` в кавычки как строки, что вызывает ошибку MariaDB:
```
SQLSTATE[42000]: Syntax error: ... near ''50' OFFSET '0''
```

**Решение:** LIMIT и OFFSET требуют прямую вставку целых чисел в SQL-строку, а не параметризацию:

```php
// Неправильно (вызывает ошибку):
$sql = 'SELECT ... LIMIT ? OFFSET ?';
$stmt->execute([$deviceUuid, $limit, $offset]);

// Правильно:
$limit = intval($limit) ?: 50;
$offset = intval($offset) ?: 0;
$sql = 'SELECT ... LIMIT ' . $limit . ' OFFSET ' . $offset;
$stmt = $this->db->prepare($sql);
$stmt->execute([$deviceUuid]);
```

### Управление жизненным циклом файлов

#### Для ТСД (сценарий 1С-ТСД)

| Действие | Метод | Удаление файла |
|----------|-------|----------------|
| Получить список заданий | GET `/api/v1/exchange/download` | Нет |
| Скачать файл задания | GET `/api/v1/exchange/files/{id}` | Нет |
| Загрузить результаты | POST `/api/v1/exchange/upload` | Нет |
| Удалить файл задания | DELETE `/api/v1/exchange/files/{id}` | **Да** (после загрузки результатов) |

#### Для backoffice (1С)

| Действие | Метод | Удаление файла |
|----------|-------|----------------|
| Получить список результатов | GET `/api/v1/exchange/download` | Нет |
| Скачать файл результатов | GET `/api/v1/exchange/files/{id}` | Нет |
| Обновить статус обработки | PUT `/api/v1/exchange/status/{id}` | Нет |

### Методы с исправлением LIMIT/OFFSET

| Метод | Назначение | Дефолтный limit | Дефолтный offset |
|-------|------------|-----------------|------------------|
| `getPendingForDevice()` | ТСД - только pending файлы | 50 | 0 |
| `getIncomingForDevice()` | ТСД - все входящие | 50 | 0 |
| `getIncomingForBackoffice()` | Backoffice - все от ТСД | 50 | 0 |

### Ограничения

| Параметр | Минимум | Максимум |
|----------|---------|----------|
| limit | 1 | 100 |
| offset | 0 | Без ограничений |