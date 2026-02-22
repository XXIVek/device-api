<?php
// ID сообщения, полученного из списка (например, 123e4567-e89b-12d3-a456-426614174000)
$messageId = 'полученный_uuid';
$url = 'http://localhost/device_api/public/api/v1/messages' . $messageId;
$token = 'полученный_device_uuid'; // вместо token_device_a_123

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";