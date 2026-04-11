# API для обмена файлами между торговыми точками и Backoffice

## Обзор

Данный API реализует обмен файлами между:
- **Торговыми точками** (имеют свою лицензию и device_uuid)
- **Центральной программой (Backoffice)** (имеет специальную лицензию с INN = `BACKOFFICE001`)

### Основные принципы

1. **Однонаправленная передача**: Торговые точки отправляют файлы только в Backoffice
2. **Аутентификация**: Все запросы требуют заголовок `Authorization: Bearer <device_uuid>`
3. **Валидация файлов**: Проверяется размер (макс. 10MB), тип, расширение и содержимое
4. **Статусы обработки**: Backoffice может обновлять статус обработки файла

---

## Алгоритм работы

### 1. Регистрация лицензии (первичная настройка)

**Запрос:**
```http
POST /api/v1/licenses
Content-Type: application/json

{
  "license": "<строка лицензии из 1С>"
}
```

**Ответ:**
```json
{
  "license_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "device_uuid": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
  "organization": {
    "inn": "7701234567",
    "name": "ООО Ромашка",
    "city": "Москва"
  }
}
```

**Важно:** Сохраните `device_uuid` для использования в заголовке `Authorization`.

---

### 2. Отправка файла из торговой точки в Backoffice

**Запрос:**
```http
POST /api/v1/exchange/send
Authorization: Bearer <device_uuid_торговой_точки>
Content-Type: multipart/form-data

Form data:
- file: <бинарный файл>
- message: {"doc_type": "UPD", "doc_number": "123", "doc_date": "2025-01-15"}
- subject: УПД №123 от 15.01.2025 (опционально)
```

**Ответ (201 Created):**
```json
{
  "status": "ok",
  "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "backoffice_device_uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
}
```

**Коды ошибок:**
- `400` - Файл не прошёл валидацию или отсутствует поле `message`
- `403` - Лицензия отправителя не активна
- `500` - Backoffice не настроен в системе

---

### 3. Получение статуса отправки файла (для торговой точки)

**Запрос:**
```http
GET /api/v1/exchange/outgoing/{message_id}/status
Authorization: Bearer <device_uuid_торговой_точки>
```

**Ответ:**
```json
{
  "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "status": "delivered",
  "delivered_at": "2025-01-15T10:30:00Z",
  "exchange_status": "processed",
  "exchange_comment": "Файл успешно обработан"
}
```

**Возможные статусы:**
- `pending` - Ожидает обработки
- `delivered` - Доставлен получателю
- `exchange_status`: `processed` | `rejected` | `error` | `null`

---

### 4. Получение списка входящих файлов (для Backoffice)

**Запрос:**
```http
GET /api/v1/exchange/incoming?limit=50&offset=0&sender_uuid=<опционально>
Authorization: Bearer <device_uuid_backoffice>
```

**Параметры query:**
- `limit` - количество записей (по умолчанию 50, макс. 100)
- `offset` - смещение (по умолчанию 0)
- `sender_uuid` - фильтр по отправителю (опционально)

**Ответ:**
```json
{
  "total": 5,
  "limit": 50,
  "offset": 0,
  "items": [
    {
      "id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "sender_uuid": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
      "subject": "УПД №123 от 15.01.2025",
      "file_url": "/api/v1/exchange/files/a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "filename": "upd_123.xml",
      "metadata": {
        "doc_type": "UPD",
        "doc_number": "123",
        "doc_date": "2025-01-15"
      },
      "status": "pending",
      "created_at": "2025-01-15T10:00:00Z"
    }
  ]
}
```

---

### 5. Скачивание файла (для Backoffice)

**Запрос:**
```http
GET /api/v1/exchange/files/{message_id}
Authorization: Bearer <device_uuid_backoffice>
```

**Ответ:**
- Content-Type: MIME-тип файла
- Content-Disposition: attachment; filename="..."
- X-Message-ID: ID сообщения
- X-Sender-UUID: UUID отправителя
- Тело: бинарные данные файла

---

### 6. Обновление статуса обработки (для Backoffice)

**Запрос:**
```http
PUT /api/v1/exchange/status/{message_id}
Authorization: Bearer <device_uuid_backoffice>
Content-Type: application/json

{
  "status": "processed",
  "comment": "Файл успешно обработан и импортирован в 1С"
}
```

**Возможные значения status:**
- `processed` - Успешно обработан
- `rejected` - Отклонён (например, неверный формат)
- `error` - Ошибка обработки

**Ответ:**
```json
{
  "status": "ok",
  "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "exchange_status": "processed"
}
```

---

## Валидация файлов

### Разрешённые типы файлов

| Тип | Расширения | MIME-типы |
|-----|------------|-----------|
| Изображения | jpg, jpeg, png, gif | image/jpeg, image/png, image/gif |
| Документы | pdf, txt, doc, docx, xls, xlsx | application/pdf, text/plain, application/msword, ... |
| Архивы | zip | application/zip, application/x-zip-compressed |

### Ограничения

- **Максимальный размер:** 10 MB
- **Запрещённые расширения:** php, exe, bat, sh, cgi, pl, py, js, vbs, cmd, ps1, hta, msi, dll, com, scr, pif, jar, wsf, wsc
- **Проверка содержимого:** Сверяется MIME-тип с расширением, проверяются вредоносные сигнатуры

---

## Настройка Backoffice

### Шаг 1: Выполнить миграцию БД

```sql
source /path/to/migrations/001_add_exchange_fields.sql
```

### Шаг 2: Зарегистрировать лицензию Backoffice

```bash
curl -X POST https://your-server/api/v1/licenses \
  -H "Content-Type: application/json" \
  -d '{"license": "<лицензионный ключ backoffice>"}'
```

Сохраните полученный `device_uuid`.

### Шаг 3: Настроить переменную окружения

```env
BACKOFFICE_DEVICE_UUID=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

---

## Обработка ошибок

### Коды ответов HTTP

| Код | Описание |
|-----|----------|
| 200 | Успешный запрос |
| 201 | Файл успешно отправлен |
| 400 | Ошибка валидации (неверный формат, размер, тип файла) |
| 401 | Неверный или отсутствующий токен аутентификации |
| 403 | Доступ запрещён (неактивная лицензия, не backoffice) |
| 404 | Файл или сообщение не найдены |
| 500 | Внутренняя ошибка сервера |
| 503 | Сервис временно недоступен (для реализации очереди в 1С) |

### Формат ответа при ошибке

```json
{
  "error": "Описание ошибки",
  "errorCode": -101
}
```

---

## Пример кода для 1С

### Отправка файла

```bsl
Процедура ОтправитьФайлВBackoffice(Файл, Метаданные)
    
    УстройствоUUID = ПолучитьDeviceUUID(); // Из хранения
    URL = "https://your-server/api/v1/exchange/send";
    
    ЗапросHTTP = Новый HTTPЗапрос(URL);
    ЗапросHTTP.Заголовки["Authorization"] = "Bearer " + УстройствоUUID;
    
    // Формируем multipart/form-data
    Граница = СтрСоединить(Новый УникальныйИдентификатор(), "");
    ЗапросHTTP.Заголовки["Content-Type"] = "multipart/form-data; boundary=" + Граница;
    
    Тело = Новый ДвоичныеДанные;
    Поток = Новый ПотокВПамяти();
    Запись = Новый ЗаписьДанныхПотока(Поток, "UTF-8");
    
    // Поле file
    Запись.ЗаписатьСтроку("--" + Граница);
    Запись.ЗаписатьСтроку("Content-Disposition: form-data; name=""file""; filename=""" + Файл.Имя + """");
    Запись.ЗаписатьСтроку("Content-Type: application/octet-stream");
    Запись.ЗаписатьСтроку("");
    Запись.Закрыть();
    Поток.Записать(Файл.ОткрытьПотокДляЧтения());
    
    // Поле message (JSON)
    Запись = Новый ЗаписьДанныхПотока(Поток, "UTF-8");
    Запись.ЗаписатьСтроку(Символы.ПС + "--" + Граница);
    Запись.ЗаписатьСтроку("Content-Disposition: form-data; name=""message""");
    Запись.ЗаписатьСтроку("");
    Запись.ЗаписатьСтроку(СериализоватьJSON(Метаданные));
    
    // Завершение
    Запись.ЗаписатьСтроку(Символы.ПС + "--" + Граница + "--");
    Запись.Закрыть();
    
    ЗапросHTTP.УстановитьТелоИзПотока(Поток);
    
    // Отправка
    HTTPСоединение = Новый HTTPСоединение("your-server", 443, "", "", Новый ЗащищенноеСоединениеOpenSSL);
    Ответ = HTTPСоединение.ОтправитьДляОбработки(ЗапросHTTP);
    
    Если Ответ.КодСостояния = 201 Тогда
        Результат = ПрочитатьJSON(Ответ.ПолучитьТелоКакСтроку());
        Сообщить("Файл отправлен, ID: " + Результат.message_id);
    Иначе
        Сообщить("Ошибка: " + Ответ.КодСостояния);
    КонецЕсли;
    
КонецПроцедуры
```

### Получение статуса

```bsl
Функция ПолучитьСтатусФайла(MessageUUID)
    
    УстройствоUUID = ПолучитьDeviceUUID();
    URL = "https://your-server/api/v1/exchange/outgoing/" + MessageUUID + "/status";
    
    ЗапросHTTP = Новый HTTPЗапрос(URL);
    ЗапросHTTP.Заголовки["Authorization"] = "Bearer " + УстройствоUUID;
    
    HTTPСоединение = Новый HTTPСоединение("your-server", 443, "", "", Новый ЗащищенноеСоединениеOpenSSL);
    Ответ = HTTPСоединение.Получить(ЗапросHTTP);
    
    Если Ответ.КодСостояния = 200 Тогда
        Возврат ПрочитатьJSON(Ответ.ПолучитьТелоКакСтроку());
    Иначе
        Возврат Неопределено;
    КонецЕсли;
    
КонецФункции
```

---

## Рекомендации по интеграции

### Для торговых точек (1С)

1. **Кэширование device_uuid** после регистрации лицензии
2. **Повторная отправка** при получении 503/500 (реализовать очередь)
3. **Логирование** всех отправок и статусов
4. **Периодический опрос** статуса отправленных файлов

### Для Backoffice

1. **Регулярный polling** `/api/v1/exchange/incoming` (например, каждые 30 сек)
2. **Обработка файлов** в фоне с обновлением статуса
3. **Уведомления** торговым точкам об ошибках обработки
4. **Архивация** обработанных файлов

---

## Поддержка

При возникновении проблем обратитесь к логам сервера или создайте issue в репозитории проекта.
