<?php
/**
 * Тест сопряжения устройства по коду активации
 * 
 * Сценарий:
 * 1. Генерация кода активации (имитация 1С)
 * 2. Активация устройства (имитация Android) - БЕЗ отправки UUID
 * 3. Проверка статуса устройства
 */

$baseUrl = 'http://localhost:8080'; // Замените на ваш URL
$licenseUuid = 'YOUR_LICENSE_UUID'; // Замените на UUID вашей лицензии

echo "=== ТЕСТ СОПРЯЖЕНИЯ УСТРОЙСТВА ===\n\n";

// Шаг 1: Генерация кода активации
echo "Шаг 1: Генерация кода активации...\n";
$generateUrl = "$baseUrl/api/v1/pairing/generate";
$generateData = json_encode(['license_uuid' => $licenseUuid]);

$ch = curl_init($generateUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $generateData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($generateData)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
$result = json_decode($response, true);

if ($httpCode !== 200 || !$result['success']) {
    echo "Ошибка генерации кода:\n";
    echo $response . "\n";
    exit(1);
}

$activationCode = $result['activation_code'];
echo "Код активации: $activationCode\n";
echo "QR строка: " . $result['qr_string'] . "\n";
echo "Истекает через: " . $result['expires_in'] . " сек.\n\n";

// Шаг 2: Активация устройства (Android отправляет ТОЛЬКО код и имя)
echo "Шаг 2: Активация устройства (Android)...\n";
$activateUrl = "$baseUrl/api/v1/pairing/activate";
$activateData = json_encode([
    'activation_code' => $activationCode,
    'device_name' => 'Android-Test-' . date('YmdHis')
]);

$ch = curl_init($activateUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $activateData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($activateData)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
$result = json_decode($response, true);

if ($httpCode !== 200 || !$result['success']) {
    echo "Ошибка активации:\n";
    echo $response . "\n";
    exit(1);
}

$deviceUuid = $result['device_uuid'];
echo "Успешно!\n";
echo "Device UUID (сохранить как токен): $deviceUuid\n";
echo "License UUID: " . $result['license_uuid'] . "\n";
echo "Организация: " . $result['organization']['name'] . " (ИНН: " . $result['organization']['inn'] . ")\n\n";

// Шаг 3: Проверка статуса устройства
echo "Шаг 3: Проверка статуса устройства...\n";
$statusUrl = "$baseUrl/api/v1/devices/status";

$ch = curl_init($statusUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $deviceUuid
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
$result = json_decode($response, true);

if ($httpCode !== 200) {
    echo "Ошибка получения статуса:\n";
    echo $response . "\n";
    exit(1);
}

echo "Статус устройства:\n";
echo "  Сопряжено: " . ($result['status']['pairing'] ? 'Да' : 'Нет') . "\n";
echo "  Konf: " . $result['status']['konf'] . "\n";
echo "  BD: " . $result['status']['bd'] . "\n";
echo "  Input: " . $result['status']['input'] . "\n";
echo "  Output: " . $result['status']['output'] . "\n\n";

// Шаг 4: Обновление статуса (имитация работы Android)
echo "Шаг 4: Обновление статуса устройства...\n";
$updateUrl = "$baseUrl/api/v1/devices/status";
$updateData = json_encode([
    'konf' => 1,
    'bd' => 1,
    'input' => 1,
    'output' => 1
]);

$ch = curl_init($updateUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, $updateData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $deviceUuid,
    'Content-Length: ' . strlen($updateData)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
$result = json_decode($response, true);

if ($httpCode !== 200) {
    echo "Ошибка обновления статуса:\n";
    echo $response . "\n";
    exit(1);
}

echo "Статус успешно обновлён!\n\n";

echo "=== ТЕСТ ЗАВЕРШЁН УСПЕШНО ===\n";
echo "Сохраните Device UUID для дальнейшей работы: $deviceUuid\n";
