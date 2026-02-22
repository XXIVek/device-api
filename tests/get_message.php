<?php
$messageId = 'UUID_сообщения_из_ответа';
$url = 'http://localhost/device_api/public/api/v1/messages/' . $messageId;
$deviceUuid = 'полученный_device_uuid_устройства_Б';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $deviceUuid
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";