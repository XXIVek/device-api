<?php
namespace App\Services;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use Ramsey\Uuid\Uuid;
use Psr\Http\Message\UploadedFileInterface;

class FileService
{
    private $filesystem;
    private $baseDirectory;
    
    // Конфигурация валидации файлов
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    
    // Для обмена разрешены XML и DBF файлы
    private const ALLOWED_MIME_TYPES = [
        'application/xml' => ['xml'],
        'text/xml' => ['xml'],
        'application/x-dbf' => ['dbf'],
        'application/dbase' => ['dbf'],
        'application/vnd.dbf' => ['dbf'],
    ];
    
    // Запрещённые расширения (потенциально опасные)
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'phtml', 'exe', 'bat', 'sh',
        'cgi', 'pl', 'py', 'js', 'vbs', 'cmd', 'ps1', 'hta', 'msi',
        'dll', 'com', 'scr', 'pif', 'jar', 'wsf', 'wsc',
        'jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'zip'
    ];

    public function __construct(string $baseDir)
    {
        $adapter = new LocalFilesystemAdapter($baseDir);
        $this->filesystem = new Filesystem($adapter);
        $this->baseDirectory = $baseDir;
    }

    /**
     * Сохранить загруженный файл из Psr\Http\Message\UploadedFileInterface
     * Возвращает относительный путь к файлу или null в случае ошибки.
     */
    public function saveUploadedFile($uploadedFile, $deviceId): ?string
    {
        // Проверка наличия файла
        if (!$uploadedFile instanceof UploadedFileInterface) {
            return null;
        }
        
        // Проверка кода ошибки загрузки
        if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return null;
        }
        
        // Валидация файла перед сохранением
        $validationResult = $this->validateFile($uploadedFile);
        if ($validationResult !== true) {
            // Файл не прошёл валидацию
            return null;
        }

        // Генерируем уникальное имя
        $originalFilename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        
        // Дополнительная защита: если расширение пустое, пытаемся определить по MIME
        if (empty($extension)) {
            $mimeType = $this->detectMimeType($uploadedFile);
            $extension = $this->getExtensionFromMime($mimeType);
        }
        
        // Если всё ещё нет расширения, используем безопасное по умолчанию
        if (empty($extension) || !in_array($extension, array_merge(...array_values(self::ALLOWED_MIME_TYPES)))) {
            $extension = 'bin';
        }
        
        $newFilename = Uuid::uuid4()->toString() . '.' . $extension;
        // Сохраняем в подпапке device_id
        $path = $deviceId . '/' . $newFilename;

        try {
            // Используем stream для сохранения
            $stream = fopen($uploadedFile->getStream()->getMetadata('uri'), 'r');
            $this->filesystem->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            return $path;
        } catch (UnableToWriteFile $e) {
            // логирование ошибки
            return null;
        }
    }
    
    /**
     * Валидация загруженного файла
     * @param UploadedFileInterface $uploadedFile
     * @return bool|string True если валидация пройдена, строка с ошибкой если нет
     */
    private function validateFile(UploadedFileInterface $uploadedFile)
    {
        // 1. Проверка размера файла
        $size = $uploadedFile->getSize();
        if ($size === null || $size > self::MAX_FILE_SIZE) {
            return false;
        }
        
        // 2. Проверка расширения файла
        $originalFilename = $uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        
        // Запрещённые расширения
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS)) {
            return false;
        }
        
        // 3. Определение MIME-типа из содержимого файла
        $mimeType = $this->detectMimeType($uploadedFile);
        
        // 4. Проверка MIME-типа по белому списку
        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            return false;
        }
        
        // 5. Проверка соответствия расширения и MIME-типа
        $allowedExtensions = self::ALLOWED_MIME_TYPES[$mimeType];
        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }
        
        // 6. Проверка валидности XML (базовая)
        if (strpos($mimeType, 'xml') !== false || $extension === 'xml') {
            if (!$this->isValidXml($uploadedFile)) {
                return false;
            }
        }
        
        // 7. Проверка валидности DBF (базовая)
        if ($extension === 'dbf') {
            if (!$this->isValidDbf($uploadedFile)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Проверка валидности XML файла
     */
    private function isValidXml(UploadedFileInterface $uploadedFile): bool
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $content = $stream->read(1024 * 1024); // Читаем до 1MB для проверки
        $stream->rewind();
        
        // Пустой файл не валиден
        if (empty(trim($content))) {
            return false;
        }
        
        // Проверка на наличие XML декларации или кореневого элемента
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $result = $dom->loadXML($content);
        libxml_clear_errors();
        
        return $result;
    }
    
    /**
     * Проверка валидности DBF файла
     * DBF файлы имеют специфическую структуру заголовка
     */
    private function isValidDbf(UploadedFileInterface $uploadedFile): bool
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $header = $stream->read(32); // Читаем заголовок (32 байта минимум)
        $stream->rewind();
        
        // Пустой файл не валиден
        if (strlen($header) < 32) {
            return false;
        }
        
        // Проверка первого байта - версия DBF
        // 0x03 - dBASE III, 0x30 - dBASE III PLUS, 0x43 - dBASE IV, 0x8B - dBASE IV с memo, 0xF5 - FoxPro с memo
        $firstByte = ord($header[0]);
        $validVersions = [0x03, 0x30, 0x43, 0x8B, 0xF5, 0x31, 0x32, 0x42, 0x63, 0x83, 0x87, 0xCB, 0xE5, 0xF4];
        
        if (!in_array($firstByte, $validVersions)) {
            return false;
        }
        
        // Проверка что файл не пустой и имеет минимальную структуру
        $fileSize = $uploadedFile->getSize();
        if ($fileSize === null || $fileSize < 32) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Определение MIME-типа из содержимого файла
     */
    private function detectMimeType(UploadedFileInterface $uploadedFile): string
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $content = $stream->read(1024); // Читаем первые 1KB для определения типа
        $stream->rewind();
        
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);
        
        return $mimeType ?: 'application/octet-stream';
    }
    
    /**
     * Получить расширение файла по MIME-типу
     */
    private function getExtensionFromMime(string $mimeType): string
    {
        foreach (self::ALLOWED_MIME_TYPES as $mime => $extensions) {
            if ($mime === $mimeType) {
                return $extensions[0];
            }
        }
        return '';
    }
    
    /**
     * Базовая проверка на вредоносное содержимое в XML
     * Проверяет наличие XXE атак и других уязвимостей
     */
    private function checkForMaliciousContent(UploadedFileInterface $uploadedFile): bool
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();
        $content = $stream->read(64 * 1024); // Читаем первые 64KB
        $stream->rewind();
        
        // Подозрительные паттерны для XML файлов
        $suspiciousPatterns = [
            // XXE атаки (XML External Entity)
            '/<!ENTITY\s+/i',
            '/SYSTEM\s+["\']/i',
            '/PUBLIC\s+["\']/i',
            // DTD с внешними ссылками
            '/<!DOCTYPE[^>]*\[.*?>/is',
            // PHP теги в XML
            '/<\?php/i',
            '/<\?=/i',
            // JavaScript в XML
            '/<script[^>]*>/i',
            '/javascript:/i',
            // Команды оболочки
            '/\b(bash|sh|cmd|powershell)\b/i',
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Получить полный путь к файлу для чтения
     */
    public function getFullPath(string $path): string
    {
        return $this->baseDirectory . '/' . $path;
    }

    /**
     * Удалить файл
     */
    public function deleteFile(string $path): bool
    {
        try {
            $this->filesystem->delete($path);
            return true;
        } catch (UnableToDeleteFile $e) {
            return false;
        }
    }
    
    /**
     * Получить информацию о лимитах загрузки
     */
    public function getUploadLimits(): array
    {
        return [
            'max_file_size' => self::MAX_FILE_SIZE,
            'max_file_size_human' => $this->formatBytes(self::MAX_FILE_SIZE),
            'allowed_mime_types' => array_keys(self::ALLOWED_MIME_TYPES),
            'allowed_extensions' => array_merge(...array_values(self::ALLOWED_MIME_TYPES)),
            'forbidden_extensions' => self::FORBIDDEN_EXTENSIONS
        ];
    }
    
    /**
     * Форматирование размера файла в человекочитаемый вид
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
