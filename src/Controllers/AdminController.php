<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Views\Twig;
use App\Models\Admin;
use App\Models\Organization;
use App\Models\License;
use App\Models\Device;
use App\Models\Message;

class AdminController
{
    private $view;
    private $adminModel;
    private $organizationModel;
    private $licenseModel;
    private $deviceModel;
    private $messageModel;

    public function __construct(Twig $view, $db)
    {
        $this->view = $view;
        $this->adminModel = new Admin($db);
        $this->organizationModel = new Organization($db);
        $this->licenseModel = new License($db);
        $this->deviceModel = new Device($db);
        $this->messageModel = new Message($db);
    }

    // GET /admin/login
    public function loginForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/login.twig');
    }

    // POST /admin/login
    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        $admin = $this->adminModel->verifyPassword($username, $password);
        if ($admin) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $redirect = $_SESSION['login_redirect'] ?? '/admin/dashboard';
            unset($_SESSION['login_redirect']);
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        return $this->view->render($response, 'admin/login.twig', [
            'error' => 'Неверное имя пользователя или пароль'
        ]);
    }

    // GET /admin/logout
    public function logout(Request $request, Response $response): Response
    {
        unset($_SESSION['admin_id']);
        session_destroy();
        return $response->withHeader('Location', '/admin/login')->withStatus(302);
    }

    // GET /admin/dashboard
    public function dashboard(Request $request, Response $response): Response
    {
        $stats = [
            'organizations' => count($this->organizationModel->getAll()), // нужен метод getAll()
            'licenses' => count($this->licenseModel->getAll()),          // нужен метод getAll()
            'devices' => count($this->deviceModel->getAll()),            // нужен метод getAll()
            'messages' => count($this->messageModel->getAll()),          // нужен метод getAll()
        ];
        return $this->view->render($response, 'admin/dashboard.twig', [
            'stats' => $stats
        ]);
    }

    // GET /admin/organizations
    public function organizations(Request $request, Response $response): Response
    {
        $orgs = $this->organizationModel->getAll(); // нужно реализовать
        return $this->view->render($response, 'admin/organizations.twig', [
            'organizations' => $orgs
        ]);
    }

    // GET /admin/organizations/{id}
    public function organization(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $org = $this->organizationModel->findById($id); // нужно реализовать
        if (!$org) {
            return $response->withStatus(404)->write('Organization not found');
        }
        $licenses = $this->licenseModel->getByOrganizationId($id); // используем существующий метод
        return $this->view->render($response, 'admin/organization.twig', [
            'organization' => $org,
            'licenses' => $licenses
        ]);
    }

    // GET /admin/licenses
    public function licenses(Request $request, Response $response): Response
    {
        $licenses = $this->licenseModel->getAll(); // нужно реализовать
        return $this->view->render($response, 'admin/licenses.twig', [
            'licenses' => $licenses
        ]);
    }

    // GET /admin/licenses/{uuid}
    public function license(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'];
        $license = $this->licenseModel->findByUuid($uuid);
        if (!$license) {
            return $response->withStatus(404)->write('License not found');
        }
        $devices = $this->deviceModel->findByLicenseUuid($uuid); // используем существующий метод
        return $this->view->render($response, 'admin/license.twig', [
            'license' => $license,
            'devices' => $devices
        ]);
    }

    // GET /admin/devices
    public function devices(Request $request, Response $response): Response
    {
        $devices = $this->deviceModel->getAll(); // нужно реализовать
        return $this->view->render($response, 'admin/devices.twig', [
            'devices' => $devices
        ]);
    }

    // GET /admin/devices/{uuid}
    public function device(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'];
        $device = $this->deviceModel->findByDeviceUuid($uuid);
        if (!$device) {
            return $response->withStatus(404)->write('Device not found');
        }
        $messages = $this->messageModel->getForDevice($uuid); // нужно реализовать метод
        return $this->view->render($response, 'admin/device.twig', [
            'device' => $device,
            'messages' => $messages
        ]);
    }

    // GET /admin/messages
    public function messages(Request $request, Response $response): Response
    {
        $messages = $this->messageModel->getAll(); // нужно реализовать с сортировкой
        return $this->view->render($response, 'admin/messages.twig', [
            'messages' => $messages
        ]);
    }

    // GET /admin/messages/{id}
    public function message(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $message = $this->messageModel->findById($id); // нужно реализовать
        if (!$message) {
            return $response->withStatus(404)->write('Message not found');
        }
        return $this->view->render($response, 'admin/message.twig', [
            'message' => $message
        ]);
    }
}