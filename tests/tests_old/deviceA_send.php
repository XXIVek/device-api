<?php
$url = 'http://localhost/device_api/public/api/v1/messages';
$token = 'полученный_device_uuid'; // вместо token_device_a_123

// Получаем ID устройства B из базы данных (или укажите вручную)
// Для начала можно посмотреть в БД: SELECT id FROM devices WHERE name = 'Device B';
$recipientId = 2; // замените на реальный ID

$data = [
    'recipient_id' => $recipientId,
    'subject' => 'Hello from A',
    'message' => json_encode(['command' => 'turn_on', 'param' => 'light']),
];

// Если хотите отправить файл, укажите путь к реальному файлу
$filePath = null; // например, 'C:/test.jpg'

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$postFields = $data;
if ($filePath && file_exists($filePath)) {
    $postFields['file'] = new CURLFile($filePath);
}
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields); // автоматически выставит multipart/form-data

$response = curl_exec($ch);
if ($response === false) {
    echo 'Curl error: ' . curl_error($ch) . "\n";
    $httpCode = 0;
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
}
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";