<?php
$url = 'http://localhost/device_api/public/api/v1/licenses';
$licenseString = '21082010001890П1Xб ВкK" 0т "- IаXее0  8а XК  сEк 2О  д , 0р о1у   1X 0I8V1 НН 305П0 И  20220,3О5 922 К0 12О0,Оон'; // замените на реальную строку 112 символов

$data = ['license' => $licenseString];

xdebug_break();

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";