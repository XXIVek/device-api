<?php
/**
 * Тестовый скрипт для проверки эндпоинта GET /api/v1/devices/status
 * 
 * Использование:
 * 1. Замените YOUR_DEVICE_UUID на актуальный device_uuid
 * 2. Запустите через curl или браузер
 * 
 * Пример запуска через curl:
 * curl -X GET "http://localhost/device_api/public/api/v1/devices/status" \
 *   -H "Authorization: Bearer YOUR_DEVICE_UUID"
 */

$deviceUuid = 'YOUR_DEVICE_UUID'; // Замените на ваш device_uuid
$baseUrl = 'http://localhost/device_api/public';

$url = $baseUrl . '/api/v1/devices/status';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $deviceUuid,
    'Content-Type: application/json'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: " . $httpCode . "\n";
echo "Response:\n";
echo json_encode(json_decode($response, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
