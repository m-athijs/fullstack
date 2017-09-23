<?php

namespace Directus\API\Routes\A1;

use Directus\Application\Route;
use Directus\Database\TableGateway\DirectusActivityTableGateway;
use Directus\Database\TableGateway\DirectusGroupsTableGateway;
use Directus\Database\TableGateway\DirectusUsersTableGateway;
use Directus\Mail\Mail;
use Directus\Services\AuthService;
use Directus\Util\DateUtils;
use Directus\Util\StringUtils;
use Directus\View\JsonView;

class Auth extends Route
{
    public function requestToken()
    {
        $response = [
            'success' => false,
            'error' => [
                'message' => __t('incorrect_email_or_password')
            ]
        ];

        $request = $this->app->request();
        $email = $request->post('email');
        $password = $request->post('password');

        if ($email && $password) {
            $authService = new AuthService($this->app);
            $accessToken = $authService->requestToken($email, $password);

            if ($accessToken) {
                unset($response['error']);
                $response['success'] = true;
                $response['data'] = [
                    'token' => $accessToken
                ];
            }
        }

        return $this->app->response($response);
    }

    public function login()
    {
        $app = $this->app;
        $auth = $this->app->container->get('auth');
        $ZendDb = $this->app->container->get('zenddb');
        $acl = $this->app->container->get('acl');
        $response = [
            'error' => [
                'message' => __t('incorrect_email_or_password')
            ],
            'success' => false,
        ];

        if ($auth->loggedIn()) {
            $response['success'] = true;
            unset($response['error']);
            return $this->app->response($response);
        }

        $req = $app->request();
        $email = $req->post('email');
        $password = $req->post('password');
        $Users = new DirectusUsersTableGateway($ZendDb, $acl);
        $user = $Users->findOneBy('email', $email);

        if (!$user) {
            return $this->app->response($response);
        }

        // ------------------------------
        // Check if group needs whitelist
        $groupId = $user['group'];
        $directusGroupsTableGateway = new DirectusGroupsTableGateway($ZendDb, $acl);
        if (!$directusGroupsTableGateway->acceptIP($groupId, $app->request->getIp())) {
            return $this->app->response([
                'message' => 'Request not allowed from IP address',
                'success' => false
                // 'all_nonces' => $requestNonceProvider->getAllNonces()
            ]);
        }

        // =============================================================================
        // Fetch information about the latest version to the admin
        // when they first log in.
        // =============================================================================
        if (is_null($user['last_login']) && $user['group'] == 1) {
            $_SESSION['first_version_check'] = true;
        }

        // @todo: Login should fail on correct information when user is not active.
        $response['success'] = $auth->login($user['id'], $user['password'], $user['salt'], $password);

        // When the credentials are correct but the user is Inactive
        $userHasStatusColumn = array_key_exists(STATUS_COLUMN_NAME, $user);
        $isUserActive = false;
        if ($userHasStatusColumn && $user[STATUS_COLUMN_NAME] == STATUS_ACTIVE_NUM) {
            $isUserActive = true;
        }

        if ($response['success'] && !$isUserActive) {
            $auth->logout();
            $response['success'] = false;
            $response['error']['message'] = __t('login_error_user_is_not_active');

            return $this->app->response($response);
        }

        if ($response['success']) {
            // Set logged user to the ACL
            $acl->setUserId($user['id']);
            $acl->setGroupId($user['group']);

            $app->emitter->run('directus.authenticated', [$app, $user]);
            $app->emitter->run('directus.authenticated.admin', [$app, $user]);
            unset($response['message']);
            $response['last_page'] = json_decode($user['last_page']);
            $userSession = $auth->getUserInfo();
            $set = ['last_login' => DateUtils::now(), 'access_token' => $userSession['access_token']];
            $where = ['id' => $user['id']];
            $updateResult = $Users->update($set, $where);

            $Activity = new DirectusActivityTableGateway($ZendDb, $acl);
            $Activity->recordLogin($user['id']);
        }

        return $this->app->response($response);
    }

    public function logout($inactive = null)
    {
        $app = $this->app;
        $auth = $app->container->get('auth');
        if ($auth->loggedIn()) {
            $auth->logout();
        }

        if ($inactive) {
            $app->redirect(DIRECTUS_PATH . 'login.php?inactive=1');
        } else {
            $app->redirect(DIRECTUS_PATH . 'login.php');
        }
    }

    public function resetPassword($token)
    {
        $app = $this->app;
        $auth = $app->container->get('auth');
        $ZendDb = $app->container->get('zenddb');
        $acl = $app->container->get('acl');

        $DirectusUsersTableGateway = new DirectusUsersTableGateway($ZendDb, $acl);
        $user = $DirectusUsersTableGateway->findOneBy('reset_token', $token);

        if (!$user) {
            $app->halt(200, __t('password_reset_incorrect_token'));
        }

        $expirationDate = new \DateTime($user['reset_expiration'], new \DateTimeZone('UTC'));
        if (DateUtils::hasPassed($expirationDate)) {
            $app->halt(200, __t('password_reset_expired_token'));
        }

        $password = StringUtils::randomString();
        $set = [];
        // @NOTE: this is not being used for hashing the password anymore
        $set['salt'] = StringUtils::randomString();
        $set['password'] = $auth->hashPassword($password, $set['salt']);
        $set['reset_token'] = '';

        // Skip ACL
        $DirectusUsersTableGateway = new \Zend\Db\TableGateway\TableGateway('directus_users', $ZendDb);
        $affectedRows = $DirectusUsersTableGateway->update($set, ['id' => $user['id']]);

        if (1 !== $affectedRows) {
            $app->halt(200, __t('password_reset_error'));
        }

        $data = ['new_password' => $password];
        Mail::send('mail/forgot-password.twig.html', $data, function ($message) use ($user) {
            $message->setSubject(__t('password_reset_new_password_email_subject'));
            $message->setTo($user['email']);
        });

        $app->halt(200, __t('password_reset_new_temporary_password_sent'));
    }

    public function forgotPassword()
    {
        $app = $this->app;
        $auth = $app->container->get('auth');
        $ZendDb = $app->container->get('zenddb');
        $acl = $app->container->get('acl');

        $email = $app->request()->post('email');
        if (!isset($email)) {
            return $this->app->response([
                'success' => false,
                'error' => [
                    'message' => __t('password_forgot_invalid_email')
                ]
            ]);
        }

        $DirectusUsersTableGateway = new DirectusUsersTableGateway($ZendDb, $acl);
        $user = $DirectusUsersTableGateway->findOneBy('email', $email);

        if (false === $user) {
            return $this->app->response([
                'success' => false,
                'error' => [
                    'message' => __t('password_forgot_no_account_found')
                ]
            ]);
        }

        $set = [];
        $set['reset_token'] = StringUtils::randomString(30);
        $set['reset_expiration'] = DateUtils::inDays(2);

        // Skip ACL
        $DirectusUsersTableGateway = new \Zend\Db\TableGateway\TableGateway('directus_users', $ZendDb);
        $affectedRows = $DirectusUsersTableGateway->update($set, ['id' => $user['id']]);

        if (1 !== $affectedRows) {
            return $this->app->response([
                'success' => false
            ]);
        }

        $data = ['reset_token' => $set['reset_token']];
        Mail::send('mail/reset-password.twig.html', $data, function ($message) use ($user) {
            $message->setSubject(__t('password_forgot_password_reset_email_subject'));
            $message->setTo($user['email']);
        });

        return $this->app->response([
            'success' => true
        ]);
    }

    public function permissions()
    {
        $acl = $this->app->container->get('acl');

        return $this->app->response([
            'data' => $acl->getGroupPrivileges()
        ]);
    }

    public function session()
    {
        return $this->app->response($_SESSION);
    }

    public function clearSession()
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
        return $this->app->response($_SESSION);
    }
}
