# Сценарий работы ТСД с файлами заданий и результатов

## Обзор

Документ описывает полный цикл обмена файлами между ТСД и сервером для сценария **1С → Сайт → ТСД**.

---

## Полный цикл обмена ТСД

```
┌─────────────────────────────────────────────────────────────────┐
│                    ЦИКЛ ОБМЕНА ТСД                              │
│                                                                 │
│  1. Получить список заданий                                      │
│     GET /api/v1/exchange/download?limit=50&offset=0            │
│     ← список pending файлов                                      │
│                                                                 │
│  2. Скачать файл задания                                         │
│     GET /api/v1/exchange/files/{message_id}                    │
│     ← файл task.json (НЕ удаляется)                             │
│                                                                 │
│  3. Обработать файл (сканировать штрихкоды)                     │
│     [локальная работа ТСД]                                       │
│                                                                 │
│  4. Загрузить файл с результатами                               │
│     POST /api/v1/exchange/upload                               │
│     file=result.json, message={"type":"inventory_result"}      │
│     ← message_id результата                                    │
│                                                                 │
│  5. Удалить файл задания (после успешной загрузки результатов)  │
│     DELETE /api/v1/exchange/files/{message_id_задания}         │
│     ← deleted: true                                             │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Детальное описание методов

### 1. GET `/api/v1/exchange/download`

Получение списка заданий, доступных для ТСД.

**Запрос:**
```http
GET /api/v1/exchange/download?limit=50&offset=0
Authorization: Bearer <device_uuid_ТСД>
```

**Параметры:**
| Параметр | Тип | По умолчанию | Описание |
|----------|-----|--------------|----------|
| limit | integer | 50 | Количество записей (макс. 100) |
| offset | integer | 0 | Смещение |

**Ответ 200 OK:**
```json
{
  "total": 2,
  "limit": 50,
  "offset": 0,
  "items": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440001",
      "sender_uuid": "<device_uuid_1C>",
      "subject": "Задание на инвентаризацию #1",
      "metadata": {
        "type": "inventory_task",
        "date": "2024-01-15"
      },
      "file_url": "/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440001",
      "filename": "task_001.json",
      "status": "pending",
      "created_at": "2024-01-15 10:30:00"
    },
    {
      "id": "550e8400-e29b-41d4-a716-446655440002",
      "sender_uuid": "<device_uuid_1C>",
      "subject": "Задание на инвентаризацию #2",
      "metadata": {
        "type": "inventory_task",
        "date": "2024-01-16"
      },
      "file_url": "/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440002",
      "filename": "task_002.json",
      "status": "pending",
      "created_at": "2024-01-16 09:00:00"
    }
  ]
}
```

**Важно:**
- Возвращаются **только `pending`** сообщения (файлы, которые ТСД ещё не скачал)
- Файлы **НЕ удаляются** при получении списка

---

### 2. GET `/api/v1/exchange/files/{message_id}`

Скачивание конкретного файла задания.

**Запрос:**
```http
GET /api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440001
Authorization: Bearer <device_uuid_ТСД>
```

**Ответ 200 OK:**
```
Content-Type: application/json
Content-Disposition: attachment; filename="task_001.json"
X-Message-ID: 550e8400-e29b-41d4-a716-446655440001
X-Sender-UUID: <device_uuid_1C>
X-Original-Filename: task_001.json

[содержимое файла task_001.json]
```

**Важно:**
- Файл **НЕ удаляется** после скачивания
- ТСД может работать с файлом неограниченное время
- Удаление происходит **только по явному DELETE запросу** от ТСД

---

### 3. POST `/api/v1/exchange/upload`

Загрузка файла с результатами сканирования.

**Запрос:**
```http
POST /api/v1/exchange/upload
Authorization: Bearer <device_uuid_ТСД>
Content-Type: multipart/form-data

--boundary
Content-Disposition: form-data; name="file"; filename="result.json"
Content-Type: application/json

[содержимое result.json]
--boundary
Content-Disposition: form-data; name="message"

{"type":"inventory_result","task_id":"550e8400-e29b-41d4-a716-446655440001","scanned_at":"2024-01-15T12:00:00Z"}
--boundary--
```

**Ответ 201 Created:**
```json
{
  "status": "ok",
  "message_id": "660e8400-e29b-41d4-a716-446655440003",
  "backoffice_device_uuid": "<device_uuid_1C>"
}
```

**Важно:**
- Файл сохраняется с оригинальным именем
- При загрузке файла с тем же именем — старый файл **перезаписывается**
- Сообщение **обновляется** (если есть совпадение по file_path + sender_uuid)

---

### 4. DELETE `/api/v1/exchange/files/{message_id}`

Удаление файла задания **после** успешной загрузки результатов.

**Запрос:**
```http
DELETE /api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440001
Authorization: Bearer <device_uuid_ТСД>
```

**Ответ 200 OK:**
```json
{
  "status": "ok",
  "message_id": "550e8400-e29b-41d4-a716-446655440001",
  "deleted": true
}
```

**Ответ 404 Not Found:**
```json
{
  "error": "Message not found"
}
```

**Важно:**
- Удаляется **и сообщение, и файл** с диска
- Только владелец сообщения может его удалить
- Удаление **необратимо**

---

## Примеры кода для Android Studio

### Kotlin: Получить список заданий

```kotlin
suspend fun getTasks(): List<TaskItem> = withContext(Dispatchers.IO) {
    val client = OkHttpClient()
    val request = Request.Builder()
        .url("https://YOUR_SERVER/device_api/public/api/v1/exchange/download?limit=50&offset=0")
        .header("Authorization", "Bearer $deviceUuid")
        .get()
        .build()
    
    client.newCall(request).execute().use { response ->
        if (!response.isSuccessful) {
            throw Exception("Failed to get tasks: ${response.code}")
        }
        val json = response.body?.string() ?: ""
        val parsed = JSONObject(json)
        val items = parsed.getJSONArray("items")
        
        return@withContext (0 until items.length()).map { i ->
            val obj = items.getJSONObject(i)
            TaskItem(
                messageId = obj.getString("id"),
                senderUuid = obj.getString("sender_uuid"),
                subject = obj.getString("subject"),
                metadata = obj.getJSONObject("metadata"),
                fileUrl = obj.getString("file_url"),
                filename = obj.getString("filename"),
                createdAt = obj.getString("created_at")
            )
        }
    }
}

data class TaskItem(
    val messageId: String,
    val senderUuid: String,
    val subject: String,
    val metadata: JSONObject,
    val fileUrl: String,
    val filename: String,
    val createdAt: String
)
```

### Kotlin: Скачать файл задания

```kotlin
suspend fun downloadTask(messageId: String): File = withContext(Dispatchers.IO) {
    val client = OkHttpClient()
    val request = Request.Builder()
        .url("https://YOUR_SERVER/device_api/public/api/v1/exchange/files/$messageId")
        .header("Authorization", "Bearer $deviceUuid")
        .get()
        .build()
    
    client.newCall(request).execute().use { response ->
        if (!response.isSuccessful) {
            throw Exception("Failed to download file: ${response.code}")
        }
        
        // Сохраняем файл во внутреннее хранилище
        val file = File(context.filesDir, "task_$messageId.json")
        response.body?.byteStream()?.use { input ->
            file.outputStream().use { output ->
                input.copyTo(output)
            }
        }
        
        return@withContext file
    }
}
```

### Kotlin: Загрузить результаты

```kotlin
suspend fun uploadResults(resultFile: File, metadata: JSONObject): String = withContext(Dispatchers.IO) {
    val client = OkHttpClient()
    val mediaType = "application/json".toMediaType()
    
    val requestBody = MultipartBody.Builder()
        .setType(MultipartBody.FORM)
        .addPart(
            Headers.of("Content-Disposition", "form-data; name=\"file\"; filename=\"${resultFile.name}\""),
            RequestBody.create(mediaType, resultFile)
        )
        .addPart(
            Headers.of("Content-Disposition", "form-data; name=\"message\""),
            RequestBody.create("text/plain".toMediaType(), metadata.toString())
        )
        .build()
    
    val request = Request.Builder()
        .url("https://YOUR_SERVER/device_api/public/api/v1/exchange/upload")
        .header("Authorization", "Bearer $deviceUuid")
        .post(requestBody)
        .build()
    
    client.newCall(request).execute().use { response ->
        if (!response.isSuccessful) {
            throw Exception("Failed to upload results: ${response.code}")
        }
        val json = response.body?.string() ?: ""
        val parsed = JSONObject(json)
        return@withContext parsed.getString("message_id")
    }
}
```

### Kotlin: Удалить файл задания

```kotlin
suspend fun deleteTask(messageId: String): Unit = withContext(Dispatchers.IO) {
    val client = OkHttpClient()
    val request = Request.Builder()
        .url("https://YOUR_SERVER/device_api/public/api/v1/exchange/files/$messageId")
        .header("Authorization", "Bearer $deviceUuid")
        .delete()
        .build()
    
    client.newCall(request).execute().use { response ->
        if (!response.isSuccessful) {
            throw Exception("Failed to delete task: ${response.code}")
        }
        val json = response.body?.string() ?: ""
        val parsed = JSONObject(json)
        if (!parsed.getBoolean("deleted")) {
            throw Exception("Task deletion failed")
        }
    }
}
```

### Kotlin: Полный цикл в одном методе

```kotlin
/**
 * Полный цикл обработки заданий для ТСД:
 * 1. Получить список pending заданий
 * 2. Для каждого задания: скачать → обработать → загрузить результаты → удалить задание
 */
suspend fun processAllTasks(): Result {
    var processedCount = 0
    var errorCount = 0
    
    try {
        // 1. Получить список pending заданий
        val tasks = getTasks()
        
        for (task in tasks) {
            try {
                // 2. Скачать файл задания
                val taskFile = downloadTask(task.messageId)
                
                // 3. Обработать файл (сканировать штрихкоды)
                val resultFile = processTaskFile(taskFile)
                
                // 4. Загрузить результаты
                val resultMessageId = uploadResults(
                    resultFile,
                    JSONObject().apply {
                        put("type", "inventory_result")
                        put("task_id", task.messageId)
                        put("scanned_at", Instant.now().toString())
                    }
                )
                
                // 5. Удалить файл задания (после успешной загрузки результатов)
                deleteTask(task.messageId)
                
                processedCount++
                
            } catch (e: Exception) {
                errorCount++
                // Логируем ошибку, продолжаем с следующим заданием
                Log.e("TSDExchange", "Error processing task ${task.messageId}", e)
            }
        }
        
    } catch (e: Exception) {
        Log.e("TSDExchange", "Failed to get tasks", e)
        return Result(success = false, processed = 0, errors = 1)
    }
    
    return Result(success = errorCount == 0, processed = processedCount, errors = errorCount)
}

data class Result(
    val success: Boolean,
    val processed: Int,
    val errors: Int
)
```

---

## Примеры curl

### Получить список заданий
```bash
curl -v -H "Authorization: Bearer <device_uuid_ТСД>" \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/download?limit=50&offset=0"
```

### Скачать файл
```bash
curl -v -H "Authorization: Bearer <device_uuid_ТСД>" \
  -o task.json \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000"
```

### Загрузить результаты
```bash
curl -X POST \
  -H "Authorization: Bearer <device_uuid_ТСД>" \
  -F "file=@result.json" \
  -F 'message={"type":"inventory_result","task_id":"12345"}' \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/upload"
```

### Удалить файл задания
```bash
curl -v -X DELETE \
  -H "Authorization: Bearer <device_uuid_ТСД>" \
  "https://YOUR_SERVER/device_api/public/api/v1/exchange/files/550e8400-e29b-41d4-a716-446655440000"
```

---

## Коды ошибок

| Код | Сообщение | Описание |
|-----|-----------|----------|
| 400 | Invalid message ID | Неверный формат message_id |
| 403 | Access denied | Нет доступа к ресурсу |
| 404 | Message not found | Сообщение не найдено |
| 404 | File not found on server | Файл не найден на сервере |
| 500 | Internal server error | Внутренняя ошибка сервера |

---

## Важные замечания

1. **Автоматическое удаление УБРАНО** — файл не удаляется при скачивании
2. **Ручное удаление ОБЯЗАТЕЛЬНО** — ТСД должен удалить файл задания после загрузки результатов
3. **Повторное скачивание** — файл можно скачивать неограниченное количество раз
4. **Замещение файлов** — при загрузке файла с тем же именем, старый файл перезаписывается
5. **Безопасность** — только владелец сообщения может его удалить

---

## Промпт для Android Studio AI

Скопируйте этот промпт в Android Studio AI Assistant для генерации кода:

```
Мне нужно реализовать обмен файлами между Android ТСД и REST API сервером.

Стек: Kotlin, OkHttp (или Retrofit), Coroutines

Опиши и сгенерируй код для следующих методов:

### 1. Получить список заданий для ТСД

GET /api/v1/exchange/download?limit=50&offset=0
Authorization: Bearer <device_uuid>

Возвращает JSON:
{
  "total": 2,
  "limit": 50,
  "offset": 0,
  "items": [
    {
      "id": "uuid",
      "sender_uuid": "uuid",
      "subject": "Задание на инвентаризацию",
      "metadata": {"type": "inventory_task", "date": "2024-01-15"},
      "file_url": "/api/v1/exchange/files/{id}",
      "filename": "task_001.json",
      "status": "pending",
      "created_at": "2024-01-15 10:30:00"
    }
  ]
}

### 2. Скачать файл задания

GET /api/v1/exchange/files/{message_id}
Authorization: Bearer <device_uuid>

Возвращает файл с заголовками:
- Content-Type: application/json
- Content-Disposition: attachment; filename="task.json"
- X-Message-ID: {id}
- X-Sender-UUID: {uuid}
- X-Original-Filename: task.json

Сохранить файл во внутреннее хранилище приложения.

### 3. Загрузить результаты

POST /api/v1/exchange/upload
Authorization: Bearer <device_uuid>
Content-Type: multipart/form-data

Параметры:
- file: result.json (бинарный)
- message: {"type":"inventory_result","task_id":"{message_id}"}

Возвращает:
{
  "status": "ok",
  "message_id": "uuid",
  "backoffice_device_uuid": "uuid"
}

### 4. Удалить файл задания

DELETE /api/v1/exchange/files/{message_id}
Authorization: Bearer <device_uuid>

Возвращает:
{
  "status": "ok",
  "message_id": "uuid",
  "deleted": true
}

### 5. Полный цикл обработки

suspend fun processAllTasks(): Result

Который:
1. Получает список pending заданий
2. Для каждого: скачивает → обрабатывает → загружает результаты → удаляет задание
3. Возвращает количество обработанных и ошибок

Важно:
- Использовать Coroutines (Dispatchers.IO)
- Обработать все ошибки (network, HTTP codes)
- Логировать через Log.e/Log.i
- Файлы сохранять во внутреннем хранилище (context.filesDir)
```

---

## Альтернативный промпт с Retrofit

```
Мне нужно реализовать обмен файлами между Android ТСД и REST API сервером.

Стек: Kotlin, Retrofit 2, OkHttp, Gson, Coroutines

Сгенерируй:

### 1. API интерфейс

interface DeviceExchangeApi {
    @GET("api/v1/exchange/download")
    suspend fun getTasks(
        @Query("limit") limit: Int = 50,
        @Query("offset") offset: Int = 0
    ): ExchangeResponse
    
    @GET("api/v1/exchange/files/{message_id}")
    @Streaming
    suspend fun downloadFile(
        @Path("message_id") messageId: String,
        @Header("Authorization") auth: String
    ): Response<ResponseBody>
    
    @Multipart
    @POST("api/v1/exchange/upload")
    suspend fun uploadResults(
        @Part file: MultipartBody.Part,
        @Part("message") message: RequestBody
    ): UploadResponse
    
    @DELETE("api/v1/exchange/files/{message_id}")
    suspend fun deleteTask(
        @Path("message_id") messageId: String,
        @Header("Authorization") auth: String
    ): DeleteResponse
}

### 2. Data classes

- ExchangeResponse, TaskItem, UploadResponse, DeleteResponse

### 3. Repository класс

class DeviceExchangeRepository {
    private val deviceUuid = "..."
    private val baseUrl = "https://YOUR_SERVER/device_api/public/"
    
    fun getAuthHeader(): String = "Bearer $deviceUuid"
    
    suspend fun getTasks(): List<TaskItem>
    suspend fun downloadFile(messageId: String): File
    suspend fun uploadResults(resultFile: File, taskId: String): String
    suspend fun deleteTask(messageId: String)
    suspend fun processAllTasks(): Result
}

### 4. Пример использования в ViewModel

class ExchangeViewModel : ViewModel() {
    val processingState = MutableLiveData<ProcessingState>()
    val processedCount = MutableLiveData<Int>()
    val errorMessage = MutableLiveData<String>()
    
    fun processTasks() {
        viewModelScope.launch {
            try {
                val result = repository.processAllTasks()
                processingState.value = ProcessingState.SUCCESS
                processedCount.value = result.processed
            } catch (e: Exception) {
                processingState.value = ProcessingState.ERROR
                errorMessage.value = e.message
            }
        }
    }
}

enum class ProcessingState { IDLE, PROCESSING, SUCCESS, ERROR }
data class Result(val processed: Int, val errors: Int)
```
