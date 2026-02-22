<?php
$url = 'http://localhost/device_api/public/api/v1/messages';
$senderDeviceUuid = 'полученный_device_uuid_устройства_А';
$recipientDeviceUuid = 'полученный_device_uuid_устройства_Б';

$data = [
    'recipient_uuid' => $recipientDeviceUuid,
    'subject' => 'Hello',
    'message' => json_encode(['command' => 'turn_on', 'param' => 'light'])
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $senderDeviceUuid,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";