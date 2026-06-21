# Сценарий сопряжения устройств (1С + Android)

## Обзор

Данный документ описывает процесс сопряжения устройства на базе Android с системой через 1С.

## Этапы сопряжения

### 1. Регистрация лицензии (1С → Сервер)

**Запрос:**
```
POST /api/v1/licenses
Content-Type: application/json
```

**Ответ:**
```json
{
    "success": true,
    "license_uuid": "...",
    "device_uuid": "..." 
}
```

Примечание: Устройство создается автоматически при регистрации лицензии с `paired=false`.

---

### 2. Генерация кода активации (1С → Сервер)

1С запрашивает код активации для устройства, используя полученный UUID.

**Запрос:**
```
POST /api/v1/devices/generate-code
Authorization: Bearer <device_uuid>
```

**Ответ:**
```json
{
    "success": true,
    "activation_code": "ABC123",
    "qr_string": "PAIR:ABC123",
    "expires_in": 1800,
    "device_uuid": "..."
}
```

- Код состоит из 6 символов (цифры и заглавные буквы)
- Срок действия: 30 минут
- 1С отображает код пользователю (текстом или QR-кодом)
- **Важно:** UUID передается только в заголовке Authorization, в URL не требуется

---

### 3. Активация устройства (Android → Сервер)

Пользователь вводит код активации в Android-приложение (или сканирует QR-код).

**Запрос:**
```
POST /api/v1/devices/activate
Content-Type: application/json
```

**Тело запроса:**
```json
{
    "activation_code": "ABC123"
}
```

**Ответ:**
```json
{
    "success": true,
    "device_uuid": "...",
    "message": "Use this UUID as Bearer token for subsequent requests"
}
```

- Android сохраняет полученный `device_uuid`
- Этот UUID будет использоваться как Bearer-токен для всех последующих запросов
- Код активации еще не сгорает (это произойдет при отправке статуса с `pairing=true`)

---

### 4. Отправка статуса сопряжения (Android → Сервер)

Android немедленно отправляет статус устройства с флагом `pairing=true`.

**Запрос:**
```
POST /api/v1/devices/status
Authorization: Bearer <device_uuid>
Content-Type: application/json
```

**Тело запроса:**
```json
{
    "pairing": true,
    "konf": 0,
    "bd": 0,
    "input": 0,
    "output": 0
}
```

**Ответ:**
```json
{
    "status": "ok",
    "device_uuid": "...",
    "paired": true
}
```

- Сервер устанавливает `paired=true` в базе данных
- Код активации удаляется (сгорает)
- Устройство считается полностью сопряженным

---

### 5. Последующие запросы (Android → Сервер)

Все дальнейшие запросы от Android используют сохраненный UUID как токен:

```
Authorization: Bearer <device_uuid>
```

Примеры эндпоинтов:
- `GET /api/v1/devices/status` - получение текущего статуса
- `POST /api/v1/devices/status` - обновление статуса
- `GET /api/v1/exchange/incoming-for-device` - получение файлов
- `POST /api/v1/exchange/send` - отправка файлов

---

## Проверка статуса (1С → Сервер)

1С может проверить статус устройства в любой момент:

**Запрос:**
```
GET /api/v1/devices/status
Authorization: Bearer <device_uuid>
```

**Ответ:**
```json
{
    "device_uuid": "...",
    "status": {
        "pairing": true,
        "konf": 0,
        "bd": 0,
        "input": 0,
        "output": 0
    }
}
```

---

## Диаграмма последовательности

```
1С                          Сервер                       Android
 |                            |                              |
 |--[POST /licenses]--------->|                              |
 |<--[license_uuid, device_uuid]-|                          |
 |                            |                              |
 |--[POST /devices/generate-code]->|                  |
 |<[activation_code]---------|                              |
 |                            |                              |
 |=== Пользователь вводит код в Android =====================>|
 |                            |                              |
 |                            |<[POST /activate]-------------|
 |                            |[activation_code]             |
 |                            |                              |
 |                            |--[device_uuid]--------------->|
 |                            |                              |
 |                            |<[PUT /status]----------------|
 |                            |[Bearer uuid, pairing=true]   |
 |                            |                              |
 |<== 1С может проверить статус устройства ================>|
```

---

## Безопасность

- Код активации одноразовый (сгорает после установки `paired=true`)
- Срок действия кода: 30 минут
- UUID устройства используется как постоянный токен доступа
- Все запросы (кроме активации) требуют авторизации через `Authorization: Bearer <uuid>`

---

## Обработка ошибок

| Код | Описание |
|-----|----------|
| 400 | Неверный формат запроса |
| 401 | Отсутствует или неверный токен |
| 404 | Код активации не найден или истек |
| 500 | Ошибка сервера |

---

## Миграция базы данных

Перед использованием необходимо выполнить миграцию:

```bash
mysql -u user -p database < migrations/003_add_activation_code_to_devices.sql
```

Миграция добавляет поля:
- `activation_code` - код активации (6 символов)
- `code_expires_at` - время истечения кода
