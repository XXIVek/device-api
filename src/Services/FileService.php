<?php
namespace App\Services;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use Ramsey\Uuid\Uuid;

class FileService
{
    private $filesystem;
    private $baseDirectory;

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
       if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return null;
        }

        // Генерируем уникальное имя
        $extension = pathinfo($uploadedFile->getClientFilename(), PATHINFO_EXTENSION);
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

    private $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf', 'text/plain'];

}
