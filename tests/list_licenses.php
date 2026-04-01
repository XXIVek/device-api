<?php
$url = 'http://localhost/device_api/public/api/v1/licenses';
//$licenseUuid = '1d8182d1-654b-4235-b10c-dec9795d647f'; // вставьте реальный Uuid лицензии
$innString = '2329005052'; // вставьте реальный ИНН
$data = ['inn' => $innString];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
//curl_setopt($ch, CURLOPT_HTTPHEADER, [
//    'Authorization: Bearer ' . $licenseUuid
//]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";