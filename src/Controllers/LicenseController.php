<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use App\Models\Organization;
use App\Models\License;
use App\Models\Device;
use App\LicenseDecoder;

class LicenseController
{
    private $organizationModel;
    private $licenseModel;
    private $deviceModel;
    private $logger;

    public function __construct($db, $logger)
    {
        $this->organizationModel = new Organization($db);
        $this->licenseModel = new License($db);
        $this->deviceModel = new Device($db);
        $this->logger = $logger;
    }

    public function register(Request $request, Response $response): Response
    {
        $parsedBody = $request->getParsedBody();
        $licenseString = $parsedBody['license'] ?? null;

        if (!$licenseString) {
            return $this->errorResponse($response, 'License string is required', 400);
        }

        $decoder = new LicenseDecoder();
        try {
            $result = $decoder->decode($licenseString);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($response, 'Invalid license string: ' . $e->getMessage(), 400, -1);
        }

        if (!$result['success']) {
            return $this->errorResponse($response, $result['errorMessage'], 400, $result['errorCode']);
        }

        if ($result['demo']) {
            return $this->errorResponse($response, 'Demo license not allowed', 400, -99);
        }

        if (empty($result['inn'])) {
            return $this->errorResponse($response, 'INN is empty', 400, -94);
        }

        // Поиск или создание организации
        $organization = $this->organizationModel->findByINN($result['inn']);
        if (!$organization) {
            $orgData = [
                'inn' => $result['inn'],
                'kpp' => $result['kpp'],
                'organization' => $result['organization'],
                'city' => $result['city']
            ];
            $orgId = $this->organizationModel->create($orgData);
        } else {
            $orgId = $organization['id'];
        }

        // Проверка существования лицензии по номеру
        $existingLicense = $this->licenseModel->findByLicenseNumber($result['licenseNumberFromPlain']);
        if ($existingLicense) {
            $licenseUuid = $existingLicense['uuid'];
        } else {
            $licenseUuid = $this->licenseModel->create($result, $orgId);
        }

        // Создаём устройство для этого клиента
        $deviceUuid = $this->deviceModel->create($licenseUuid);

        $this->logger->info('License registered', [
            'license_number' => $result['licenseNumberFromPlain'],
            'license_uuid' => $licenseUuid,
            'device_uuid' => $deviceUuid
        ]);

        $responseData = [
            'license_uuid' => $licenseUuid,
            'device_uuid' => $deviceUuid,
            'organization' => [
                'inn' => $result['inn'],
                'name' => $result['organization'],
                'city' => $result['city']
            ]
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    public function list(Request $request, Response $response): Response
    {
        $deviceUuid = $request->getAttribute('device_uuid');
        if (!$deviceUuid) {
            return $this->errorResponse($response, 'Unauthorized', 401);
        }

        $device = $this->deviceModel->findByDeviceUuid($deviceUuid);
        if (!$device) {
            return $this->errorResponse($response, 'Device not found', 404);
        }

        $license = $this->licenseModel->findByUuid($device['license_uuid']);
        if (!$license) {
            return $this->errorResponse($response, 'License not found', 404);
        }

        $licenses = $this->licenseModel->getByOrganizationId($license['organization_id']);

        $result = [];
        foreach ($licenses as $lic) {
            $result[] = [
                'uuid' => $lic['uuid'],
                'license_number' => $lic['license_number_from_plain'],
                'version' => $lic['version'],
                'organization_name' => $lic['organization_name'],
                'city' => $lic['city']
            ];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function errorResponse(Response $response, string $message, int $status, int $code = null): Response
    {
        $data = ['error' => $message];
        if ($code !== null) {
            $data['errorCode'] = $code;
        }
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}