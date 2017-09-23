<?php

// Composer Autoloader
$loader = require __DIR__ . '/../vendor/autoload.php';

// Non-autoload components
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    return create_ping_server();
}

require $configFile;

// Define directus environment
defined('DIRECTUS_ENV')
|| define('DIRECTUS_ENV', (getenv('DIRECTUS_ENV') ? getenv('DIRECTUS_ENV') : 'production'));

switch (DIRECTUS_ENV) {
    case 'development_enforce_nonce':
    case 'development':
    case 'staging':
        break;
    case 'production':
    default:
        error_reporting(0);
        break;
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off';
$url = ($isHttps ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
define('HOST_URL', $url);
define('API_PATH', dirname(__FILE__));
define('BASE_PATH', dirname(API_PATH));

use Directus\Acl\Exception\UnauthorizedTableAlterException;
use Directus\Auth\Provider as Auth;
use Directus\Auth\RequestNonceProvider;
use Directus\Bootstrap;
use Directus\Db\SchemaManager;
use Directus\Db\TableGateway\DirectusActivityTableGateway;
use Directus\Db\TableGateway\DirectusBookmarksTableGateway;
use Directus\Db\TableGateway\DirectusGroupsTableGateway;
use Directus\Db\TableGateway\DirectusMessagesRecipientsTableGateway;
use Directus\Db\TableGateway\DirectusMessagesTableGateway;
use Directus\Db\TableGateway\DirectusPreferencesTableGateway;
use Directus\Db\TableGateway\DirectusPrivilegesTableGateway;
use Directus\Db\TableGateway\DirectusSettingsTableGateway;
use Directus\Db\TableGateway\DirectusUiTableGateway;
use Directus\Db\TableGateway\DirectusUsersTableGateway;
use Directus\Db\TableGateway\RelationalTableGatewayWithConditions as TableGateway;
use Directus\Db\TableSchema;
use Directus\Exception\ExceptionHandler;
use Directus\Mail\Mail;
use Directus\MemcacheProvider;
use Directus\Util\ArrayUtils;
use Directus\Util\DateUtils;
use Directus\Util\SchemaUtils;
use Directus\Util\StringUtils;
use Directus\View\ExceptionView;
use Directus\View\JsonView;

// API Version shortcut for routes:
$v = API_VERSION;

/**
 * Slim App & Directus Providers
 */

$app = Bootstrap::get('app');
$requestNonceProvider = new RequestNonceProvider();

/**
 * Load Registered Hooks
 */
$config = Bootstrap::get('config');
if (array_key_exists('hooks', $config)) {
    load_registered_hooks($config['hooks'], false);
}

if (array_key_exists('filters', $config)) {
    // set seconds parameter "true" to add as filters
    load_registered_hooks($config['filters'], true);
}

$app->add(new \Directus\Slim\CorsMiddleware());

/**
 * Creates and /<version>/ping endpoint
 *
 * To verify the server is working
 * But it's actually to check if mod_rewrite is working :)
 *
 * Only available when it's not in production
 *
 * @param \Slim\Slim $app
 */
$pong = function(\Slim\Slim $app) {
    $request = $app->request();
    $requestUri = trim($request->getResourceUri(), '/');
    $parts = explode('/', $requestUri);
    array_shift($parts);
    $requestUri = implode('/', $parts);

    if ($requestUri === 'ping') {
        if (ob_get_level() !== 0) {
            ob_clean();
        }

        echo 'pong';
        exit;
    }
};
if (DIRECTUS_ENV !== 'production') {
    $pong($app);
}

/**
 * Catch user-related exceptions & produce client responses.
 */

$app->config('debug', false);
$exceptionView = new ExceptionView();
$exceptionHandler = function (\Exception $exception) use ($app, $exceptionView) {
    $app->emitter->run('application.error', [$exception]);
    $config = $app->container->get('config');
    if (ArrayUtils::get($config, 'app.debug', true)) {
        $exceptionView->exceptionHandler($app, $exception);
    } else {
        $response = $app->response();
        $response->header('Content-type', 'application/json');
        return $app->response([
            'error' => [
                'message' => $exception->getMessage()
            ]
        ], ['error' => true]);
    }
};
$app->error($exceptionHandler);
// // Catch runtime erros etc. as well
// set_exception_handler($exceptionHandler);
$exceptionHandler = new ExceptionHandler;

// Routes which do not need protection by the authentication and the request
// nonce enforcement.
// @TODO: Move this to a middleware
$authAndNonceRouteWhitelist = [
    'auth_login',
    'auth_logout',
    'auth_session',
    'auth_clear_session',
    'auth_nonces',
    'auth_reset_password',
    'auth_permissions',
    'debug_acl_poc',
    'ping_server',
    'request_token',
];

/**
 * Bootstrap Providers
 */

/**
 * @var \Zend\Db\Adapter\Adapter
 */
$ZendDb = Bootstrap::get('ZendDb');

/**
 * @var \Directus\Acl\Acl
 */
$acl = Bootstrap::get('acl');

$app->emitter->run('application.boot', $app);

$app->hook('slim.before.dispatch', function () use ($app, $requestNonceProvider, $authAndNonceRouteWhitelist, $ZendDb, $acl) {
    // API/Server is about to initialize
    $app->emitter->run('application.init', $app);

    /** Skip routes which don't require these protections */
    $routeName = $app->router()->getCurrentRoute()->getName();
    if (!in_array($routeName, $authAndNonceRouteWhitelist)) {
        $headers = $app->request()->headers();
        $authToken = false;
        if ($app->request()->get('access_token')) {
            $authToken = $app->request()->get('access_token');
        } elseif ($headers->has('Php-Auth-User')) {
            $authUser = $headers->get('Php-Auth-User');
            $authPassword = $headers->get('Php-Auth-Pw');
            if ($authUser && empty($authPassword)) {
                $authToken = $authUser;
            }
        } elseif ($headers->has('Authorization')) {
            $authorizationHeader = $headers->get('Authorization');
            if (preg_match("/Bearer\s+(.*)$/i", $authorizationHeader, $matches)) {
                $authToken = $matches[1];
            }
        }

        if ($authToken) {
            // @TODO: Users without group shouldn't be allow to log in
            $DirectusUsersTableGateway = new \Zend\Db\TableGateway\TableGateway('directus_users', $ZendDb);
            $user = $DirectusUsersTableGateway->select(['token' => $authToken]);
            $userFound = $user->count() > 0 ? true : false;

            if (!$userFound) {
                $app->halt(401, __t('you_must_be_logged_in_to_access_the_api'));
            }

            $user = $user->toArray();
            $user = reset($user);

            // ------------------------------
            // Check if group needs whitelist
            $groupId = $user['group'];
            $directusGroupsTableGateway = new DirectusGroupsTableGateway($acl, $ZendDb);
            if (!$directusGroupsTableGateway->acceptIP($groupId, $app->request->getIp())) {
                $app->response->setStatus(401);
                $app->response([
                    'message' => 'Request not allowed from IP address',
                    'success' => false
                ]);
                return $app->stop();
            }

            // Uf the request it's done by authentication
            // Store the session information in a global variable
            // And we retrieve this information back to session at the end of the execution.
            // See slim.after hook.
            $GLOBALS['__SESSION'] = $_SESSION;
            // Reset SESSION values
            $_SESSION = [];

            Auth::setLoggedUser($user['id']);
            $app->emitter->run('directus.authenticated', [$app, $user]);
            $app->emitter->run('directus.authenticated.token', [$app, $user]);

            // Reload all user permissions
            // At this point ACL has run and loaded all permissions
            // This behavior works as expected when you are logged to the CMS/Management
            // When logged through API we need to reload all their permissions
            $privilegesTable = new DirectusPrivilegesTableGateway($acl, $ZendDb);
            $acl->setGroupPrivileges($privilegesTable->getGroupPrivileges($user['group']));
            // @TODO: Adding an user should auto set its ID and GROUP
            $acl->setUserId($user['id']);
            $acl->setGroupId($user['group']);
        }

        /** Enforce required authentication. */
        if (!Auth::loggedIn()) {
            $app->halt(401, __t('you_must_be_logged_in_to_access_the_api'));
        }

        /** Enforce required request nonces. */
        // NOTE: do no use nonce until it's well implemented
        // OR in fact if it's actually necessary.
        // nonce needs to be checked
        // otherwise an error is thrown
        if (!$requestNonceProvider->requestHasValidNonce() && !$authToken) {
            //     if('development' !== DIRECTUS_ENV) {
            //         $app->halt(401, __t('invalid_request_nonce'));
            //     }
        }

        // User is authenticated
        // And Directus is about to start
        $app->emitter->run('directus.start', $app);

        /** Include new request nonces in the response headers */
        $response = $app->response();
        $newNonces = $requestNonceProvider->getNewNoncesThisRequest();
        $nonce_options = $requestNonceProvider->getOptions();
        $response[$nonce_options['nonce_response_header']] = implode($newNonces, ',');
    }

    $permissions = $app->container->get('acl');
    $permissions->setUserId($acl->getUserId());
    $permissions->setGroupId($acl->getGroupId());
    $permissions->setGroupPrivileges($acl->getGroupPrivileges());
    $app->container->set('auth', new Auth());

    \Directus\Database\TableSchema::setAclInstance($permissions);
    \Directus\Database\TableSchema::setConnectionInstance($ZendDb);
    \Directus\Database\TableSchema::setConfig(Bootstrap::get('config'));
    \Directus\Database\TableGateway\BaseTableGateway::setHookEmitter($app->container->get('emitter'));

    $app->container->set('schemaManager', Bootstrap::get('schemaManager'));
});

$app->hook('slim.after', function () use ($app) {
    // retrieve session from global
    // if the session exists on globals it means this is a request with basic authentication
    if (array_key_exists('__SESSION', $GLOBALS)) {
        $_SESSION = $GLOBALS['__SESSION'];
    }

    // API/Server is about to shutdown
    $app->emitter->run('application.shutdown', $app);
});

/**
 * Authentication
 */

$DirectusUsersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
Auth::setUserCacheRefreshProvider(function ($userId) use ($DirectusUsersTableGateway) {
    static $users = [];
    $cacheFn = function () use ($userId, $DirectusUsersTableGateway) {
        return $DirectusUsersTableGateway->find($userId);
    };
    if (isset($users[$userId])) {
        return $users[$userId];
    }

    $cacheKey = MemcacheProvider::getKeyDirectusUserFind($userId);
    $user = $DirectusUsersTableGateway->memcache->getOrCache($cacheKey, $cacheFn, 10800);

    $users[$userId] = $user;

    return $user;
});

if (Auth::loggedIn()) {
    $user = Auth::getUserRecord();
    $acl->setUserId($user['id']);
    $acl->setGroupId($user['group']);
}

/**
 * Request Payload
 */

// @TODO: Do not use PARAMS or PAYLOAD as global variable
// @TODO: Use the Slim request instead of the global php $_GET
$params = $app->request->get();
// @TODO: Use the post method instead of parsing the body ourselves.
if ($app->request->getContentType() === 'application/json') {
    $requestPayload = json_decode($app->request->getBody(), true);
} else {
    $requestPayload = $app->request->post();
}

$endpoints = Bootstrap::getCustomEndpoints();
foreach ($endpoints as $endpoint) {
    require $endpoint;
}

/**
 * Extension Alias
 */
if (isset($_REQUEST['run_extension']) && $_REQUEST['run_extension']) {
    // Validate extension name
    $extensionName = $_REQUEST['run_extension'];
    if (!Bootstrap::extensionExists($extensionName)) {
        header('HTTP/1.0 404 Not Found');
        return $app->response(['message' => __t('no_such_extensions')]);
    }
    // Validate request nonce
    // NOTE: do no use nonce until it's well implemented
    // OR in fact if it's actually necessary.
    // nonce needs to be checked
    // otherwise an error is thrown
    if (!$requestNonceProvider->requestHasValidNonce()) {
        //     if('development' !== DIRECTUS_ENV) {
        //         header("HTTP/1.0 401 Unauthorized");
        //         return JsonView::render(array('message' => __t('unauthorized_nonce')));
        //     }
    }
    $extensionsDirectory = APPLICATION_PATH . '/customs/extensions';
    $responseData = require "$extensionsDirectory/$extensionName/api.php";
    $nonceOptions = $requestNonceProvider->getOptions();
    $newNonces = $requestNonceProvider->getNewNoncesThisRequest();

    if (!is_array($responseData)) {
        throw new \RuntimeException(__t('extension_x_must_return_array_got_y_instead', [
            'extension_name' => $extensionName,
            'type' => gettype($responseData)
        ]));
    }

    return $app->response($responseData)->setHeader($nonceOptions['nonce_response_header'],  implode($newNonces, ','));
}

$app->group('/1.1', function() use($app) {
    // =============================================================================
    // Authentication
    // =============================================================================
    $app->post('/auth/request-token/?', '\Directus\API\Routes\A1\Auth:requestToken')
        ->name('request_token');
    $app->post('/auth/login/?', '\Directus\API\Routes\A1\Auth:login')
        ->name('auth_login');
    $app->get('/auth/logout(:/inactive)/?', '\Directus\API\Routes\A1\Auth:logout')
        ->name('auth_logout');
    $app->get('/auth/reset-password/:token/?', '\Directus\API\Routes\A1\Auth:resetPassword')
        ->name('auth_reset_password');
    $app->post('/auth/forgot-password/?', '\Directus\API\Routes\A1\Auth:forgotPassword')
        ->name('auth_forgot_password');
    $app->get('/auth/permissions/?', '\Directus\API\Routes\A1\Auth:permissions')
        ->name('auth_permissions');

    // =============================================================================
    // UTILS
    // =============================================================================
    $app->post('/hash/?', '\Directus\API\Routes\A1\Utils:hash')->name('utils_hash');
    $app->post('/random/?', '\Directus\API\Routes\A1\Utils:randomString')->name('utils_random');

    // =============================================================================
    // Privileges
    // =============================================================================
    $app->get('/privileges/:groupId(/:tableName)/?', '\Directus\API\Routes\A1\Privileges:showPrivileges');
    $app->post('/privileges/:groupId/?', '\Directus\API\Routes\A1\Privileges:createPrivileges');
    $app->put('/privileges/:groupId/:privilegeId/?', '\Directus\API\Routes\A1\Privileges:updatePrivileges');

    // =============================================================================
    // ENTRIES COLLECTION
    // =============================================================================
    $app->map('/tables/:table/rows/?', '\Directus\API\Routes\A1\Entries:rows')
        ->via('GET', 'POST', 'PUT');
    $app->map('/tables/:table/rows/:id/?', '\Directus\API\Routes\A1\Entries:row')
        ->via('DELETE', 'GET', 'PUT', 'PATCH');
    $app->map('/tables/:table/rows/bulk/?', '\Directus\API\Routes\A1\Entries:rowsBulk')
        ->via('POST', 'PATCH', 'PUT', 'DELETE');
    $app->get('/tables/:table/typeahead/?', '\Directus\API\Routes\A1\Entries:typeAhead');

    // =============================================================================
    // ACTIVITY
    // =============================================================================
    $app->get('/activity/?', '\Directus\API\Routes\A1\Activity:activity');

    // =============================================================================
    // COLUMNS
    // =============================================================================
    // GET all table columns, or POST one new table column
    $app->map('/tables/:table/columns/?', '\Directus\API\Routes\A1\Table:columns')
        ->via('GET', 'POST');
    // GET or PUT one column
    $app->map('/tables/:table/columns/:column/?', '\Directus\API\Routes\A1\Table:column')
        ->via('GET', 'PUT', 'DELETE');
    $app->post('/tables/:table/columns/:column/?', '\Directus\API\Routes\A1\Table:postColumn');

    // =============================================================================
    // GROUPS
    // =============================================================================
    $app->map('/groups/?', '\Directus\API\Routes\A1\Groups:groups')
        ->via('GET', 'POST');
    $app->get('/groups/:id/?', '\Directus\API\Routes\A1\Groups:group');

    // =============================================================================
    // FILES
    // =============================================================================
    $app->map('/files(/:id)/?', '\Directus\API\Routes\A1\Files:files')
        ->via('GET', 'PATCH', 'POST', 'PUT');

    // =============================================================================
    // UPLOAD
    // =============================================================================
    $app->post('/upload/?', '\Directus\API\Routes\A1\Files:upload');
    $app->post('/upload/link/?', '\Directus\API\Routes\A1\Files:uploadLink');

    // =============================================================================
    // PREFERENCES
    // =============================================================================
    $app->map('/tables/:table/preferences/?', '\Directus\API\Routes\A1\Preferences:mapPreferences')
        ->via('GET', 'POST', 'PUT', 'DELETE');

    $app->get('/preferences/:table', '\Directus\API\Routes\A1\Preferences:getPreferences');

    // =============================================================================
    // BOOKMARKS
    // =============================================================================
    $app->get('/bookmarks/self/?', '\Directus\API\Routes\A1\Bookmarks:selfBookmarks');
    $app->get('/bookmarks/user/:id?', '\Directus\API\Routes\A1\Bookmarks:userBookmarks');
    $app->get('/bookmarks/?', '\Directus\API\Routes\A1\Bookmarks:allBookmarks');
    $app->map('/bookmarks(/:id)/?', '\Directus\API\Routes\A1\Bookmarks:bookmarks')
        ->via('POST', 'PUT', 'DELETE');

    // =============================================================================
    // REVISIONS
    // =============================================================================
    $app->get('/tables/:table/rows/:id/revisions/?', '\Directus\API\Routes\A1\Revisions:revisions');

    // =============================================================================
    // SETTINGS
    // =============================================================================
    $app->map('/settings(/:id)/?', '\Directus\API\Routes\A1\Settings:settings')
        ->via('GET', 'POST', 'PUT');

    // =============================================================================
    // TABLES
    // =============================================================================
    $app->get('/tables/?', '\Directus\API\Routes\A1\Table:names');
    $app->post('/tables/?', '\Directus\API\Routes\A1\Table:create')
        ->name('table_create');
    // GET and PUT table details
    $app->map('/tables/:table/?', '\Directus\API\Routes\A1\Table:info')
        ->via('GET', 'PUT', 'DELETE')
        ->name('table_meta');

    // =============================================================================
    // COLUMN UI
    // =============================================================================
    $app->map('/tables/:table/columns/:column/:ui/?', '\Directus\API\Routes\A1\Table:columnUi')
        ->via('GET', 'POST', 'PUT');

    // =============================================================================
    // MESSAGES
    // =============================================================================
    $app->get('/messages/rows/?', '\Directus\API\Routes\A1\Messages:rows');
    $app->get('/messages/user/:id/?', '\Directus\API\Routes\A1\Messages:rows');
    $app->get('/messages/self/?', '\Directus\API\Routes\A1\Messages:rows');
    $app->get('/messages/rows/:id/?', '\Directus\API\Routes\A1\Messages:row');
    // @TODO: this will perform an actual "get message by id"
    // $app->get('/messages/:id/?', '\Directus\API\Routes\A1\Messages:row');
    $app->map('/messages/rows/:id/?', '\Directus\API\Routes\A1\Messages:patchRow')
        ->via('PATCH');
    $app->post('/messages/rows/?', '\Directus\API\Routes\A1\Messages:postRows');
    $app->get('/messages/recipients/?', '\Directus\API\Routes\A1\Messages:recipients');
    $app->post('/comments/?', '\Directus\API\Routes\A1\Messages:comments');

    // =============================================================================
    // USERS
    // =============================================================================
    $app->map('/users/?', '\Directus\API\Routes\A1\Users:all')
        ->via('GET', 'POST', 'PUT');
    $app->map('/users/:id/?', '\Directus\API\Routes\A1\Users:get')
        ->via('DELETE', 'GET', 'PUT', 'PATCH');

    // =============================================================================
    // DEBUG
    // =============================================================================
    if ('production' !== DIRECTUS_ENV) {
        $app->get('/auth/session/?', '\Directus\API\Routes\A1\Auth:session')
            ->name('auth_session');
        $app->get('/auth/clear-session/?', '\Directus\API\Routes\A1\Auth:clearSession')
            ->name('auth_clear_session');
    }
});


/**
 * Slim Routes
 * (Collections arranged alphabetically)
 */

$app->post("/$v/auth/request-token/?", function() use ($app, $ZendDb) {
    $response = [
        'success' => false,
        'message' => __t('incorrect_email_or_password'),
    ];

    $request = $app->request();
    // @NOTE: Slim request do not parse a json request body
    //        We need to parse it ourselves
    if ($request->getMediaType() == 'application/json') {
        $jsonRequest = json_decode($request->getBody(), true);
        $email = ArrayUtils::get($jsonRequest, 'email', false);
        $password = ArrayUtils::get($jsonRequest, 'password', false);
    } else {
        $email = $request->post('email');
        $password = $request->post('password');
    }

    if ($email && $password) {
        $user = Auth::getUserByAuthentication($email, $password);

        if ($user) {
            unset($response['message']);
            $response['success'] = true;
            $response['data'] = [
                'token' => $user['token']
            ];
        }
    }

    return $app->response($response);
})->name('request_token');

$app->post("/$v/auth/login/?", function () use ($app, $ZendDb, $acl, $requestNonceProvider) {
    if (Auth::loggedIn()) {
        return $app->response(['success' => true]);
    }

    $req = $app->request();
    $email = $req->post('email');
    $password = $req->post('password');
    $Users = new DirectusUsersTableGateway($acl, $ZendDb);
    $user = $Users->findOneBy('email', $email);

    if (!$user) {
        return $app->response([
            'message' => __t('incorrect_email_or_password'),
            'success' => false,
            'all_nonces' => $requestNonceProvider->getAllNonces()
        ]);
    }

    // ------------------------------
    // Check if group needs whitelist
    $groupId = $user['group'];
    $directusGroupsTableGateway = new DirectusGroupsTableGateway($acl, $ZendDb);
    if (!$directusGroupsTableGateway->acceptIP($groupId, $app->request->getIp())) {
        return $app->response([
            'message' => 'Request not allowed from IP address',
            'success' => false,
            'all_nonces' => $requestNonceProvider->getAllNonces()
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
    $loginSuccessful = Auth::login($user['id'], $user['password'], $user['salt'], $password);

    // When the credentials are correct but the user is Inactive
    $userHasStatusColumn = array_key_exists(STATUS_COLUMN_NAME, $user);
    $isUserActive = false;
    if ($userHasStatusColumn && $user[STATUS_COLUMN_NAME] == STATUS_ACTIVE_NUM) {
        $isUserActive = true;
    }

    if ($loginSuccessful  && !$isUserActive) {
        Auth::logout();

        return $app->response([
            'success' => false,
            'message' => __t('login_error_user_is_not_active')
        ]);
    }

    if ($loginSuccessful) {
        $app->emitter->run('directus.authenticated', [$app, $user]);
        $app->emitter->run('directus.authenticated.admin', [$app, $user]);

        $response['last_page'] = json_decode($user['last_page']);
        $userSession = Auth::getUserInfo();
        $set = ['last_login' => DateUtils::now(), 'access_token' => $userSession['access_token']];
        $where = ['id' => $user['id']];
        $updateResult = $Users->update($set, $where);
        $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
        $Activity->recordLogin($user['id']);

        // =============================================================================
        // Sends a unique random token to help us understand approximately how many instances of Directus exist.
        // This can be disabled in your config file.
        // =============================================================================
        $config = Bootstrap::get('config');
        $feedbackConfig = ArrayUtils::get($config, 'feedback', []);
        if (ArrayUtils::get($feedbackConfig, 'login', false)) {
            feedback_login_ping(ArrayUtils::get($feedbackConfig, 'token', ''));
        }
    }

    return $app->response([
        'success' => true,
        'all_nonces' => $requestNonceProvider->getAllNonces()
    ]);
})->name('auth_login');

$app->get("/$v/auth/logout(/:inactive)", function ($inactive = null) use ($app) {
    if (Auth::loggedIn()) {
        Auth::logout();
    }
    if ($inactive) {
        $app->redirect(DIRECTUS_PATH . 'login.php?inactive=1');
    } else {
        $app->redirect(DIRECTUS_PATH . 'login.php');
    }
})->name('auth_logout');

$app->get("/$v/auth/nonces/?", function () use ($app, $requestNonceProvider) {
    return $app->response([
        'nonces' => $requestNonceProvider->getAllNonces()
    ]);
})->name('auth_nonces');

// debug helper
$app->get("/$v/auth/session/?", function () use ($app) {
    if ('production' === DIRECTUS_ENV) {
        return $app->halt('404');
    }

    return $app->response($_SESSION);
})->name('auth_session');

// debug helper
$app->get("/$v/auth/clear-session/?", function () use ($app) {
    if ('production' === DIRECTUS_ENV) {
        return $app->halt('404');
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();

    return $app->response($_SESSION);
})->name('auth_clear_session');

// debug helper
$app->get("/$v/auth/reset-password/:token/?", function ($token) use ($app, $acl, $ZendDb) {
    $DirectusUsersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
    $user = $DirectusUsersTableGateway->findOneBy('reset_token', $token);

    if (!$user) {
        $app->halt(200, __t('password_reset_incorrect_token'));
    }

    $expirationDate = new DateTime($user['reset_expiration'], new DateTimeZone('UTC'));
    if (DateUtils::hasPassed($expirationDate)) {
        $app->halt(200, __t('password_reset_expired_token'));
    }

    $password = StringUtils::randomString();
    $set = [];
    // @NOTE: this is not being used for hashing the password anymore
    $set['salt'] = StringUtils::randomString();
    $set['password'] = Auth::hashPassword($password, $set['salt']);
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

})->name('auth_reset_password');

$app->post("/$v/auth/forgot-password/?", function () use ($app, $acl, $ZendDb) {
    if (!isset($_POST['email'])) {
        return $app->response([
            'success' => false,
            'message' => __t('password_forgot_invalid_email')
        ]);
    }

    $DirectusUsersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
    $user = $DirectusUsersTableGateway->findOneBy('email', $_POST['email']);

    if (false === $user) {
        return $app->response([
            'success' => false,
            'message' => __t('password_forgot_no_account_found')
        ]);
    }

    $set = [];
    $set['reset_token'] = StringUtils::randomString(30);
    $set['reset_expiration'] = DateUtils::inDays(2);

    // Skip ACL
    $DirectusUsersTableGateway = new \Zend\Db\TableGateway\TableGateway('directus_users', $ZendDb);
    $affectedRows = $DirectusUsersTableGateway->update($set, ['id' => $user['id']]);

    if (1 !== $affectedRows) {
        return $app->response([
            'success' => false
        ]);
    }

    $data = ['reset_token' => $set['reset_token']];
    Mail::send('mail/reset-password.twig.html', $data, function ($message) use ($user) {
        $message->setSubject(__t('password_forgot_password_reset_email_subject'));
        $message->setTo($user['email']);
    });

    $success = true;
    return $app->response([
        'success' => $success
    ]);

})->name('auth_permissions');

// debug helper
$app->get("/$v/auth/permissions/?", function () use ($app, $acl) {
    if ('production' === DIRECTUS_ENV) {
        return $app->halt('404');
    }

    $groupPrivileges = $acl->getGroupPrivileges();
    return $app->response(['groupPrivileges' => $groupPrivileges]);
})->name('auth_permissions');

$app->post("/$v/hash/?", function () use ($app) {
    if (!(isset($_POST['password']) && !empty($_POST['password']))) {
        return $app->response([
            'success' => false,
            'message' => __t('hash_must_provide_string')
        ]);
    }

    $salt = isset($_POST['salt']) && !empty($_POST['salt']) ? $_POST['salt'] : '';
    $hashedPassword = Auth::hashPassword($_POST['password'], $salt);
    return $app->response([
        'success' => true,
        'password' => $hashedPassword
    ]);
});

$app->post("/$v/random/?", function () use ($app) {
    // default random string length
    $length = 32;
    if (array_key_exists('length', $_POST)) {
        $length = (int)$_POST['length'];
    }

    $randomString = StringUtils::randomString($length);

    return $app->response([
        'random' => $randomString
    ]);
});

$app->get("/$v/privileges/:groupId(/:tableName)/?", function ($groupId, $tableName = null) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserRecord();
    $myGroupId = $currentUser['group'];

    if ($myGroupId != 1) {
        throw new Exception(__t('permission_denied'));
    }

    $privileges = new DirectusPrivilegesTableGateway($acl, $ZendDb);
    $response = $privileges->fetchPerTable($groupId, $tableName);
    if (!$response) {
        $app->response()->setStatus(404);
        $response = [
            'message' => __t('unable_to_find_privileges_for_x_in_group_x', ['table' => $tableName, 'group_id' => $groupId]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => 'directus_privileges']);
});

$app->map("/$v/privileges/:groupId/?", function ($groupId) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserRecord();
    $myGroupId = $currentUser['group'];

    if ($myGroupId != 1) {
        throw new Exception(__t('permission_denied'));
    }

    if (isset($requestPayload['addTable'])) {
        $isTableNameAlphanumeric = preg_match("/[a-z0-9]+/i", $requestPayload['table_name']);
        $zeroOrMoreUnderscoresDashes = preg_match("/[_-]*/i", $requestPayload['table_name']);

        if (!($isTableNameAlphanumeric && $zeroOrMoreUnderscoresDashes)) {
            $app->response->setStatus(400);
            return $app->response(['message' => __t('invalid_table_name')], ['table' => 'directus_privileges']);
        }

        unset($requestPayload['addTable']);

        if (!SchemaManager::tableExists($requestPayload['table_name'])) {
            $app->emitter->run('table.create:before', $requestPayload['table_name']);
            // Through API:
            // Remove spaces and symbols from table name
            // And in lowercase
            $requestPayload['table_name'] = SchemaUtils::cleanTableName($requestPayload['table_name']);
            SchemaManager::createTable($requestPayload['table_name']);
            $app->emitter->run('table.create', $requestPayload['table_name']);
            $app->emitter->run('table.create:after', $requestPayload['table_name']);
        }
    }

    $privileges = new DirectusPrivilegesTableGateway($acl, $ZendDb);
    $response = $privileges->insertPrivilege($requestPayload);

    return $app->response($response, ['table' => 'directus_privileges']);
})->via('POST');

$app->map("/$v/privileges/:groupId/:privilegeId", function ($groupId, $privilegeId) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserRecord();
    $myGroupId = $currentUser['group'];

    if ($myGroupId != 1) {
        throw new Exception(__t('permission_denied'));
    }

    $privileges = new DirectusPrivilegesTableGateway($acl, $ZendDb);;

    if (isset($requestPayload['activeState'])) {
        if ($requestPayload['activeState'] !== 'all') {
            $priv = $privileges->findByStatus($requestPayload['table_name'], $requestPayload['group_id'], $requestPayload['activeState']);
            if ($priv) {
                $requestPayload['id'] = $priv['id'];
                $requestPayload['status_id'] = $priv['status_id'];
            } else {
                unset($requestPayload['id']);
                $requestPayload['status_id'] = $requestPayload['activeState'];
                $response = $privileges->insertPrivilege($requestPayload);
                return $app->response($response, ['table' => 'directus_privileges']);
            }
        }
    }

    $response = $privileges->updatePrivilege($requestPayload);

    return $app->response($response, ['table' => 'directus_privileges']);
})->via('PUT');

/**
 * ENTRIES COLLECTION
 */

$app->map("/$v/tables/:table/rows/?", function ($table) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $entriesService = new \Directus\Services\EntriesService($app);
    $payload = $app->request()->post();
    $params = $app->request()->get();

    // GET all table entries
    $tableGateway = new TableGateway($acl, $table, $ZendDb);

    switch ($app->request()->getMethod()) {
        case 'POST':
            $newRecord = $entriesService->createEntry($table, $payload, $params);
            $params[$tableGateway->primaryKeyFieldName] = $newRecord[$tableGateway->primaryKeyFieldName];
            break;
        case 'PUT':
            if (!is_numeric_array($payload)) {
                $params[$tableGateway->primaryKeyFieldName] = $payload[$tableGateway->primaryKeyFieldName];
                $payload = [$payload];
            }
            $tableGateway->updateCollection($payload);
            break;
    }

    $entries = $tableGateway->getEntries($params);
    return $app->response($entries, ['table' => $table]);
})->via('GET', 'POST', 'PUT');

$app->map("/$v/tables/:table/rows/bulk/?", function ($table) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $rows = array_key_exists('rows', $requestPayload) ? $requestPayload['rows'] : false;
    if (!is_array($rows) || count($rows) <= 0) {
        throw new Exception(__t('rows_no_specified'));
    }

    $TableGateway = new TableGateway($acl, $table, $ZendDb);
    $primaryKeyFieldName = $TableGateway->primaryKeyFieldName;

    $rowIds = [];
    foreach ($rows as $row) {
        if (!array_key_exists($primaryKeyFieldName, $row)) {
            throw new Exception(__t('row_without_primary_key_field'));
        }
        array_push($rowIds, $row[$primaryKeyFieldName]);
    }

    $where = new \Zend\Db\Sql\Where;

    if ($app->request()->isDelete()) {
        $TableGateway->delete($where->in($primaryKeyFieldName, $rowIds));
    } else {
        foreach ($rows as $row) {
            $TableGateway->updateCollection($row);
        }
    }

    $entries = $TableGateway->getEntries($params);
    return $app->response($entries, ['table' => $table]);
})->via('POST', 'PATCH', 'PUT', 'DELETE');

$app->get("/$v/tables/:table/typeahead/?", function ($table, $query = null) use ($ZendDb, $acl, $params, $app) {
    $Table = new TableGateway($acl, $table, $ZendDb);

    if (!isset($params['columns'])) {
        $params['columns'] = '';
    }

    $columns = ($params['columns']) ? explode(',', $params['columns']) : [];
    if (count($columns) > 0) {
        $params['group_by'] = $columns[0];

        if (isset($params['q'])) {
            $params['adv_where'] = "`{$columns[0]}` like '%{$params['q']}%'";
            $params['perPage'] = 50;
        }
    }

    if (!$query) {
        $entries = $Table->getEntries($params);
    }

    $entries = $entries['rows'];
    $response = [];
    foreach ($entries as $entry) {
        $val = '';
        $tokens = [];
        foreach ($columns as $col) {
            array_push($tokens, $entry[$col]);
        }
        $val = implode(' ', $tokens);
        array_push($response, ['value' => $val, 'tokens' => $tokens, 'id' => $entry['id']]);
    }

    return $app->response($response, ['table' => $table]);
});

$app->map("/$v/tables/:table/rows/:id/?", function ($table, $id) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserInfo();
    $params['table_name'] = $table;

    // any UPDATE requests should md5 the email
    if ('directus_users' === $table &&
        in_array($app->request()->getMethod(), ['PUT', 'PATCH']) &&
        array_key_exists('email', $requestPayload)
    ) {
        $avatar = DirectusUsersTableGateway::get_avatar($requestPayload['email']);
        $requestPayload['avatar'] = $avatar;
    }

    $TableGateway = new TableGateway($acl, $table, $ZendDb);
    switch ($app->request()->getMethod()) {
        // PUT an updated table entry
        case 'PATCH':
        case 'PUT':
            $requestPayload[$TableGateway->primaryKeyFieldName] = $id;
            $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
            $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;
            $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
            break;
        // DELETE a given table entry
        case 'DELETE':
            $success = (bool) $TableGateway->delete([$TableGateway->primaryKeyFieldName => $id]);
            return $app->response(['success' => $success], ['table' => $table]);
    }

    $params[$TableGateway->primaryKeyFieldName] = $id;
    // GET a table entry
    $Table = new TableGateway($acl, $table, $ZendDb);
    $response = $Table->getEntries($params);
    if (!$response) {
        $response = [
            'message' => __t('unable_to_find_record_in_x_with_id_x', ['table' => $table, 'id' => $id]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => $table]);
})->via('DELETE', 'GET', 'PUT', 'PATCH');

/**
 * ACTIVITY COLLECTION
 */

// @todo: create different activity endpoints
// ex: /activity/:table, /activity/recents/:days
$app->get("/$v/activity/?", function () use ($app, $params, $ZendDb, $acl) {
    $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
    // @todo move this to backbone collection
    if (!ArrayUtils::has($params, 'adv_search')) {
        unset($params['perPage']);
        $params['adv_search'] = 'datetime >= "' . DateUtils::daysAgo(30) . '"';
    }

    $new_get = $Activity->fetchFeed($params);
    $new_get['active'] = $new_get['total'];

    return $app->response($new_get, ['table' => 'directus_activity']);
});

/**
 * COLUMNS COLLECTION
 */

// GET all table columns, or POST one new table column

$app->map("/$v/tables/:table/columns/?", function ($table_name) use ($ZendDb, $params, $requestPayload, $app, $acl) {
    $params['table_name'] = $table_name;
    if ($app->request()->isPost()) {
        /**
         * @todo  check if a column by this name already exists
         * @todo  build this into the method when we shift its location to the new layer
         */
        if (!$acl->hasTablePrivilege($table_name, 'alter')) {
            throw new UnauthorizedTableAlterException(__t('permission_table_alter_access_forbidden_on_table', [
                'table_name' => $table_name
            ]));
        }

        $tableGateway = new TableGateway($acl, $table_name, $ZendDb);
        // Through API:
        // Remove spaces and symbols from column name
        // And in lowercase
        $requestPayload['column_name'] = SchemaUtils::cleanColumnName($requestPayload['column_name']);
        $params['column_name'] = $tableGateway->addColumn($table_name, $requestPayload);
    }

    $response = TableSchema::getSchemaArray($table_name, $params);
    return $app->response($response, ['table' => $table_name]);
})->via('GET', 'POST');

// GET or PUT one column

$app->map("/$v/tables/:table/columns/:column/?", function ($table, $column) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    if ($app->request()->isDelete()) {
        $tableGateway = new TableGateway($acl, $table, $ZendDb);
        $success = $tableGateway->dropColumn($column);

        $response = [
            'message' => __t('unable_to_remove_column_x', ['column_name' => $column]),
            'success' => false
        ];

        if ($success) {
            $response['success'] = true;
            $response['message'] = __t('column_x_was_removed');
        }

        return $app->response($response, ['table' => $table, 'column' => $column]);
    }

    $params['column_name'] = $column;
    $params['table_name'] = $table;
    // This `type` variable is used on the client-side
    // Not need on server side.
    // @TODO: We should probably stop using it on the client-side
    unset($requestPayload['type']);
    // Add table name to dataset. @TODO more clarification would be useful
    // Also This would return an Error because of $row not always would be an array.
    if ($requestPayload) {
        foreach ($requestPayload as &$row) {
            if (is_array($row)) {
                $row['table_name'] = $table;
            }
        }
    }

    if ($app->request()->isPut()) {
        $TableGateway = new TableGateway($acl, 'directus_columns', $ZendDb);
        $columnData = $TableGateway->select([
            'table_name' => $table,
            'column_name' => $column
        ])->current();

        if ($columnData) {
            $columnData = $columnData->toArray();
            $requestPayload = ArrayUtils::pick($requestPayload, [
                'data_type',
                'ui',
                'hidden_input',
                'hidden_list',
                'required',
                'relationship_type',
                'related_table',
                'junction_table',
                'junction_key_left',
                'junction_key_right',
                'sort',
                'comment'
            ]);

            $requestPayload['id'] = $columnData['id'];
            $TableGateway->updateCollection($requestPayload);
        }
    }

    $response = TableSchema::getSchemaArray($table, $params);
    if (!$response) {
        $response = [
            'message' => __t('unable_to_find_column_x', ['column' => $column]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => $table, 'column' => $column]);
})->via('GET', 'PUT', 'DELETE');

$app->post("/$v/tables/:table/columns/:column/?", function ($table, $column) use ($ZendDb, $acl, $requestPayload, $app) {
    $TableGateway = new TableGateway($acl, 'directus_columns', $ZendDb);
    $data = $requestPayload;
    // @TODO: check whether this condition is still needed
    if (isset($data['type'])) {
        $data['data_type'] = $data['type'];
        $data['relationship_type'] = $data['type'];
        unset($data['type']);
    }
    //$data['column_name'] = $data['junction_key_left'];
    $data['column_name'] = $column;
    $data['table_name'] = $table;
    $row = $TableGateway->findOneByArray(['table_name' => $table, 'column_name' => $column]);

    if ($row) {
        $data['id'] = $row['id'];
    }
    $newRecord = $TableGateway->manageRecordUpdate('directus_columns', $data, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
    $_POST['id'] = $newRecord['id'];

    return $app->response($_POST, ['table' => $table, 'column' => $column]);
});
/**
 * GROUPS COLLECTION
 */

/** (Optional slim route params break when these two routes are merged) */

$app->map("/$v/groups/?", function () use ($app, $ZendDb, $acl, $requestPayload) {
    // @TODO need PUT
    $tableName = 'directus_groups';
    $GroupsTableGateway = new TableGateway($acl, $tableName, $ZendDb);
    switch ($app->request()->getMethod()) {
        case 'POST':
            $newRecord = $GroupsTableGateway->manageRecordUpdate($tableName, $requestPayload);
            $newGroupId = $newRecord['id'];
            $newGroup = $GroupsTableGateway->parseRecord($GroupsTableGateway->find($newGroupId));
            $outputData = $newGroup;
            break;
        case 'GET':
        default:
            $get_new = $GroupsTableGateway->getEntries();
            $outputData = $get_new;
    }

    return $app->response($outputData, ['table' => $tableName]);
})->via('GET', 'POST');

$app->get("/$v/groups/:id/?", function ($id = null) use ($app, $ZendDb, $acl) {
    // @TODO need POST and PUT
    // Hardcoding ID temporarily
    is_null($id) ? $id = 1 : null;
    $tableName = 'directus_groups';
    $Groups = new TableGateway($acl, $tableName, $ZendDb);
    $response = $Groups->find($id);
    if (!$response) {
        $response = [
            'message' => __t('unable_to_find_group_with_id_x', ['id' => $id]),
            'success' => false
        ];
    }

    $columns = TableSchema::getAllNonAliasTableColumns($tableName);
    $response = SchemaManager::parseRecordValuesByType($response, $columns);

    return $app->response($response, ['table' => $tableName]);
});

/**
 * FILES COLLECTION
 */

$app->map("/$v/files(/:id)/?", function ($id = null) use ($app, $ZendDb, $acl, $params, $requestPayload) {
    if (!is_null($id))
        $params['id'] = $id;

    $table = 'directus_files';
    $currentUser = Auth::getUserInfo();
    $TableGateway = new TableGateway($acl, $table, $ZendDb);
    $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
    $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;

    switch ($app->request()->getMethod()) {
        case 'POST':
            $requestPayload['user'] = $currentUser['id'];
            $requestPayload['date_uploaded'] = DateUtils::now();

            // When the file is uploaded there's not a data key
            if (array_key_exists('data', $requestPayload)) {
                $Files = $app->container->get('files');
                if (!array_key_exists('type', $requestPayload) || strpos($requestPayload['type'], 'embed/') === 0) {
                    $recordData = $Files->saveEmbedData($requestPayload);
                } else {
                    $recordData = $Files->saveData($requestPayload['data'], $requestPayload['name']);
                }

                $requestPayload = array_merge($recordData, ArrayUtils::omit($requestPayload, ['data', 'name']));
            }
            $newRecord = $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
            $params['id'] = $newRecord['id'];
            break;
        case 'PATCH':
            $requestPayload['id'] = $id;
        case 'PUT':
            if (!is_null($id)) {
                $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
                break;
            }
    }

    $Files = new TableGateway($acl, $table, $ZendDb);
    $response = $Files->getEntries($params);
    if (!$response) {
        $response = [
            'message' => __t('unable_to_find_file_with_id_x', ['id' => $id]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => $table]);
})->via('GET', 'PATCH', 'POST', 'PUT');

/**
 * PREFERENCES COLLECTION
 */

$app->map("/$v/tables/:table/preferences/?", function ($table) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserInfo();
    $params['table_name'] = $table;
    $Preferences = new DirectusPreferencesTableGateway($acl, $ZendDb);
    $TableGateway = new TableGateway($acl, 'directus_preferences', $ZendDb);
    switch ($app->request()->getMethod()) {
        case 'PUT':
            $TableGateway->manageRecordUpdate('directus_preferences', $requestPayload, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
            break;
        case 'POST':
            //If Already exists and not saving with title, then updateit!
            $existing = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $table, isset($requestPayload['title']) ? $requestPayload['title'] : null);
            if (!empty($existing)) {
                $requestPayload['id'] = $existing['id'];
            }
            $requestPayload['user'] = $currentUser['id'];
            $id = $TableGateway->manageRecordUpdate('directus_preferences', $requestPayload, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
            break;
        case 'DELETE':
            if ($requestPayload['user'] != $currentUser['id']) {
                return;
            }

            if (isset($requestPayload['id'])) {
                echo $TableGateway->delete(['id' => $requestPayload['id']]);
            } else if (isset($requestPayload['title']) && isset($requestPayload['table_name'])) {
                $jsonResponse = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $requestPayload['table_name'], $requestPayload['title']);
                if ($jsonResponse['id']) {
                    echo $TableGateway->delete(['id' => $jsonResponse['id']]);
                } else {
                    echo 1;
                }
            }

            return;
    }

    //If Title is set then return this version
    if (isset($requestPayload['title'])) {
        $params['newTitle'] = $requestPayload['title'];
    }

    if (isset($params['newTitle'])) {
        $jsonResponse = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $table, $params['newTitle']);
    } else {
        $jsonResponse = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $table);
    }

    if (!$jsonResponse) {
        $app->response()->setStatus(404);
        $jsonResponse = [
            'message' => __t('unable_to_find_preferences'),
            'success' => false
        ];
    }

    return $app->response($jsonResponse, ['table' => 'directus_preferences']);
})->via('GET', 'POST', 'PUT', 'DELETE');

$app->get("/$v/preferences/:table", function ($table) use ($app, $ZendDb, $acl) {
    $currentUser = Auth::getUserInfo();
    $params['table_name'] = $table;
    $Preferences = new DirectusPreferencesTableGateway($acl, $ZendDb);
    $jsonResponse = $Preferences->fetchSavedPreferencesByUserAndTable($currentUser['id'], $table);

    return $app->response($jsonResponse, ['table' => 'directus_preferences']);
});

/**
 * BOOKMARKS COLLECTION
 */

$app->map("/$v/bookmarks(/:id)/?", function ($id = null) use ($params, $app, $ZendDb, $acl, $requestPayload) {
    $currentUser = Auth::getUserInfo();
    $bookmarks = new DirectusBookmarksTableGateway($acl, $ZendDb);
    switch ($app->request()->getMethod()) {
        case 'PUT':
            $bookmarks->updateBookmark($requestPayload);
            $id = $requestPayload['id'];
            break;
        case 'POST':
            $requestPayload['user'] = $currentUser['id'];
            $id = $bookmarks->insertBookmark($requestPayload);
            break;
        case 'DELETE':
            $bookmark = $bookmarks->fetchByUserAndId($currentUser['id'], $id);
            if ($bookmark) {
                echo $bookmarks->delete(['id' => $id]);
            }
            return;
    }
    $jsonResponse = $bookmarks->fetchByUserAndId($currentUser['id'], $id);

    return $app->response($jsonResponse, ['table' => 'directus_bookmarks']);
})->via('GET', 'POST', 'PUT', 'DELETE');

/**
 * REVISIONS COLLECTION
 */

$app->get("/$v/tables/:table/rows/:id/revisions/?", function ($table, $id) use ($app, $acl, $ZendDb, $params) {
    $params['table_name'] = $table;
    $params['id'] = $id;
    $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
    $revisions = $Activity->fetchRevisions($id, $table);

    return $app->response($revisions, ['table' => $table]);
});

/**
 * SETTINGS COLLECTION
 */

$app->map("/$v/settings(/:id)/?", function ($id = null) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $Settings = new DirectusSettingsTableGateway($acl, $ZendDb);

    switch ($app->request()->getMethod()) {
        case 'POST':
        case 'PUT':
            $data = $requestPayload;
            unset($data['id']);
            $Settings->setValues($id, $data);
            break;
    }

    $response = $Settings->fetchAll();
    if (!is_null($id)) {
        $response = array_key_exists($id, $response) ? $response[$id] : null;
    }

    if (!$response) {
        $response = [
            'message' => __t('unable_to_find_setting_collection_x', ['collection' => $id]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => 'directus_settings']);
})->via('GET', 'POST', 'PUT');

/**
 * /tables
 * List of viewable tables for the authenticated user group
 *
 * return list of objects with the name of the table
 * Ex. [{name: 'articles'}, {name: 'projects'}]
 */
$app->get("/$v/tables/?", function () use ($ZendDb, $acl, $app) {
    $tablesNames = TableSchema::getTablenames(false);

    $tables = array_map(function ($table) {
        return ['table_name' => $table];
    }, $tablesNames);

    return $app->response($tables, ['table' => 'directus_tables']);
});

// GET and PUT table details
$app->map("/$v/tables/:table/?", function ($table) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    if ($app->request()->isDelete()) {
        $tableGateway = new TableGateway($acl, $table, $ZendDb);
        $success = $tableGateway->drop();

        $response = [
            'message' => __t('unable_to_remove_table_x', ['table_name' => $table]),
            'success' => false
        ];

        if ($success) {
            $response['success'] = true;
            $response['message'] = __t('table_x_was_removed');
        }

        return $app->response($response, ['table' => 'directus_tables']);
    }

    $TableGateway = new TableGateway($acl, 'directus_tables', $ZendDb, null, null, null, 'table_name');
    $ColumnsTableGateway = new TableGateway($acl, 'directus_columns', $ZendDb);
    /* PUT updates the table */
    if ($app->request()->isPut()) {
        $data = $requestPayload;
        $table_settings = [
            'table_name' => $data['table_name'],
            'hidden' => (int)$data['hidden'],
            'single' => (int)$data['single'],
            'footer' => (int)$data['footer'],
            'primary_column' => array_key_exists('primary_column', $data) ? $data['primary_column'] : ''
        ];

        //@TODO: Possibly pretty this up so not doing direct inserts/updates
        $set = $TableGateway->select(['table_name' => $table])->toArray();

        //If item exists, update, else insert
        if (count($set) > 0) {
            $TableGateway->update($table_settings, ['table_name' => $table]);
        } else {
            $TableGateway->insert($table_settings);
        }

        $column_settings = [];
        foreach ($data['columns'] as $col) {
            $columnData = [
                'table_name' => $table,
                'column_name' => $col['column_name'],
                'ui' => $col['ui'],
                'hidden_input' => $col['hidden_input'] ? 1 : 0,
                'hidden_list' => $col['hidden_list'] ? 1 : 0,
                'required' => $col['required'] ? 1 : 0,
                'sort' => array_key_exists('sort', $col) ? $col['sort'] : 99999,
                'comment' => array_key_exists('comment', $col) ? $col['comment'] : ''
            ];

            // hotfix #1069 single_file UI not saving relational settings
            $extraFields = ['data_type', 'relationship_type', 'related_table', 'junction_key_right'];
            foreach ($extraFields as $field) {
                if (array_key_exists($field, $col)) {
                    $columnData[$field] = $col[$field];
                }
            }

            $existing = $ColumnsTableGateway->select(['table_name' => $table, 'column_name' => $col['column_name']])->toArray();
            if (count($existing) > 0) {
                $columnData['id'] = $existing[0]['id'];
            }

            array_push($column_settings, $columnData);
        }


        $ColumnsTableGateway->updateCollection($column_settings);
    }

    $response = TableSchema::getTable($table);

    if (!$response) {
        $response = [
            'message' => __t('unable_to_find_table_x', ['table_name' => $table]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => 'directus_tables']);
})->via('GET', 'PUT', 'DELETE')->name('table_meta');

/**
 * UPLOAD COLLECTION
 */

$app->post("/$v/upload/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    // $Transfer = new Files\Transfer();
    // $Storage = new Files\Storage\Storage();
    $Files = Bootstrap::get('app')->container->get('files');
    $result = [];
    foreach ($_FILES as $file) {
        $result[] = $Files->upload($file);
    }

    return $app->response($result);
});

$app->post("/$v/upload/link/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $Files = Bootstrap::get('app')->container->get('files');
    $result = [
        'message' => __t('invalid_unsupported_url'),
        'success' => false
    ];

    $app->response->setStatus(400);

    if (isset($_POST['link']) && filter_var($_POST['link'], FILTER_VALIDATE_URL)) {
        $fileData = ['caption' => '', 'tags' => '', 'location' => ''];
        $linkInfo = $Files->getLink($_POST['link']);

        if ($linkInfo) {
            $currentUser = Auth::getUserInfo();
            $app->response->setStatus(200);
            $fileData = array_merge($fileData, $linkInfo);

            $result = [];
            $result[] = [
                'type' => $fileData['type'],
                'name' => $fileData['name'],
                'title' => $fileData['title'],
                'tags' => $fileData['tags'],
                'caption' => $fileData['caption'],
                'location' => $fileData['location'],
                'charset' => $fileData['charset'],
                'size' => $fileData['size'],
                'width' => $fileData['width'],
                'height' => $fileData['height'],
                'html' => isset($fileData['html']) ? $fileData['html'] : null,
                'embed_id' => (isset($fileData['embed_id'])) ? $fileData['embed_id'] : '',
                'data' => (isset($fileData['data'])) ? $fileData['data'] : null,
                'user' => $currentUser['id']
                //'date_uploaded' => $fileData['date_uploaded'] . ' UTC',
            ];
        }
    }

    return $app->response($result);
});

$app->get("/$v/messages/rows/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();

    if (isset($_GET['max_id'])) {
        $messagesRecipientsTableGateway = new DirectusMessagesRecipientsTableGateway($acl, $ZendDb);
        $ids = $messagesRecipientsTableGateway->getMessagesNewerThan($_GET['max_id'], $currentUser['id']);
        if (sizeof($ids) > 0) {
            $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
            $result = $messagesTableGateway->fetchMessagesInboxWithHeaders($currentUser['id'], $ids);
            return $app->response($result, ['table' => 'directus_messages']);
        } else {
            $result = $messagesRecipientsTableGateway->countMessages($currentUser['id']);
            return $app->response($result, ['table' => 'directus_messages']);
        }
    }

    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $result = $messagesTableGateway->fetchMessagesInboxWithHeaders($currentUser['id']);

    return $app->response($result, ['table' => 'directus_messages']);
});

$app->get("/$v/messages/rows/:id/?", function ($id) use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();
    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $message = $messagesTableGateway->fetchMessageWithRecipients($id, $currentUser['id']);

    if (!isset($message)) {
        header('HTTP/1.0 404 Not Found');
        return $app->response(['message' => __t('message_not_found')], [
            'error' => true,
            'table' => 'directus_messages'
        ]);
    }

    return $app->response($message, ['table' => 'directus_messages']);
});

$app->map("/$v/messages/rows/:id/?", function ($id) use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();
    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $messagesRecipientsTableGateway = new DirectusMessagesRecipientsTableGateway($acl, $ZendDb);

    $message = $messagesTableGateway->fetchMessageWithRecipients($id, $currentUser['id']);

    $ids = [$message['id']];
    $message['read'] = 1;

    foreach ($message['responses']['rows'] as &$response) {
        $ids[] = $response['id'];
        $response['read'] = 1;
    }

    $messagesRecipientsTableGateway->markAsRead($ids, $currentUser['id']);

    return $app->response($message, ['table' => 'directus_messages']);
})->via('PATCH');

$app->post("/$v/messages/rows/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();

    // Unpack recipients
    $recipients = explode(',', $requestPayload['recipients']);
    $groupRecipients = [];
    $userRecipients = [];

    foreach ($recipients as $recipient) {
        $typeAndId = explode('_', $recipient);
        if ($typeAndId[0] == 0) {
            $userRecipients[] = $typeAndId[1];
        } else {
            $groupRecipients[] = $typeAndId[1];
        }
    }

    if (count($groupRecipients) > 0) {
        $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
        $result = $usersTableGateway->findActiveUserIdsByGroupIds($groupRecipients);
        foreach ($result as $item) {
            $userRecipients[] = $item['id'];
        }
    }

    $userRecipients[] = $currentUser['id'];

    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $id = $messagesTableGateway->sendMessage($requestPayload, array_unique($userRecipients), $currentUser['id']);

    if ($id) {
        $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
        $requestPayload['id'] = $id;
        $Activity->recordMessage($requestPayload, $currentUser['id']);
    }

    foreach ($userRecipients as $recipient) {
        $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
        $user = $usersTableGateway->findOneBy('id', $recipient);

        if (isset($user) && $user['email_messages'] == 1) {
            $data = ['message' => $requestPayload['message']];
            $view = 'mail/notification.twig.html';
            Mail::send($view, $data, function ($message) use ($user, $requestPayload) {
                $message->setSubject($requestPayload['subject']);
                $message->setTo($user['email']);
            });
        }
    }

    $message = $messagesTableGateway->fetchMessageWithRecipients($id, $currentUser['id']);

    return $app->response($message, ['table' => 'directus_messages']);
});

$app->get("/$v/messages/recipients/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $tokens = explode(' ', $_GET['q']);

    $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
    $users = $usersTableGateway->findUserByFirstOrLastName($tokens);

    $groupsTableGateway = new DirectusGroupsTableGateway($acl, $ZendDb);
    $groups = $groupsTableGateway->findUserByFirstOrLastName($tokens);

    $result = array_merge($groups, $users);

    return $app->response($result, ['table' => 'directus_messages']);
});

$app->post("/$v/comments/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();
    $params['table_name'] = 'directus_messages';
    $TableGateway = new TableGateway($acl, 'directus_messages', $ZendDb);

    $groupRecipients = [];
    $userRecipients = [];

    preg_match_all('/@\[.*? /', $requestPayload['message'], $results);
    $results = $results[0];

    if (count($results) > 0) {
        foreach ($results as $result) {
            $result = substr($result, 2);
            $typeAndId = explode('_', $result);
            if ($typeAndId[0] == 0) {
                $userRecipients[] = $typeAndId[1];
            } else {
                $groupRecipients[] = $typeAndId[1];
            }
        }

        if (count($groupRecipients) > 0) {
            $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
            $result = $usersTableGateway->findActiveUserIdsByGroupIds($groupRecipients);
            foreach ($result as $item) {
                $userRecipients[] = $item['id'];
            }
        }

        $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
        $id = $messagesTableGateway->sendMessage($requestPayload, array_unique($userRecipients), $currentUser['id']);
        $requestPayload['id'] = $params['id'] = $id;

        preg_match_all('/@\[.*?\]/', $requestPayload['message'], $results);
        $messageBody = $requestPayload['message'];
        $results = $results[0];

        $recipientString = '';
        $len = count($results);
        $i = 0;
        foreach ($results as $result) {
            $newresult = substr($result, 0, -1);
            $newresult = substr($newresult, strpos($newresult, ' ') + 1);
            $messageBody = str_replace($result, $newresult, $messageBody);

            if ($i == $len - 1) {
                if ($i > 0) {
                    $recipientString .= ' and ' . $newresult;
                } else {
                    $recipientString .= $newresult;
                }
            } else {
                $recipientString .= $newresult . ', ';
            }
            $i++;
        }

        foreach ($userRecipients as $recipient) {
            $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
            $user = $usersTableGateway->findOneBy('id', $recipient);

            if (isset($user) && $user['email_messages'] == 1) {
                $data = ['message' => $requestPayload['message']];
                $view = 'mail/notification.twig.html';
                Mail::send($view, $data, function ($message) use ($user, $requestPayload) {
                    $message->setSubject($requestPayload['subject']);
                    $message->setTo($user['email']);
                });
            }
        }
    }

    $requestPayload['datetime'] = DateUtils::now();
    $newRecord = $TableGateway->manageRecordUpdate('directus_messages', $requestPayload, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
    $params['id'] = $newRecord['id'];

    // GET all table entries
    $entries = $TableGateway->getEntries($params);

    return $app->response($entries, ['table' => 'directus_messages']);
});

/**
 * EXCEPTION LOG
 */
//$app->post("/$v/exception/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
//    print_r($requestPayload);die();
//    $data = array(
//        'server_addr'   =>$_SERVER['SERVER_ADDR'],
//        'server_port'   =>$_SERVER['SERVER_PORT'],
//        'user_agent'    =>$_SERVER['HTTP_USER_AGENT'],
//        'http_host'     =>$_SERVER['HTTP_HOST'],
//        'request_uri'   =>$_SERVER['REQUEST_URI'],
//        'remote_addr'   =>$_SERVER['REMOTE_ADDR'],
//        'page'          =>$requestPayload['page'],
//        'message'       =>$requestPayload['message'],
//        'user_email'    =>$requestPayload['user_email'],
//        'type'          =>$requestPayload['type']
//    );
//
//    $ctx = stream_context_create(array(
//        'http' => array(
//            'method' => 'POST',
//            'content' => "json=".json_encode($data)."&details=".$requestPayload['details']
//        ))
//    );
//
//    $fp = @fopen($url, 'rb', false, $ctx);
//
//    if (!$fp) {
//        $response = "Failed to log error. File pointer could not be initialized.";
//        $app->getLog()->warn($response);
//    }
//
//    $response = @stream_get_contents($fp);
//
//    if ($response === false) {
//        $response = "Failed to log error. stream_get_contents failed.";
//        $app->getLog()->warn($response);
//    }
//
//    $result = array('response'=>$response);
//
//    JsonView::render($result);
//});

/**
 * UI COLLECTION
 */

$app->map("/$v/tables/:table/columns/:column/:ui/?", function ($table, $column, $ui) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $TableGateway = new TableGateway($acl, 'directus_ui', $ZendDb);
    switch ($app->request()->getMethod()) {
        case 'PUT':
        case 'POST':
            $keys = ['table_name' => $table, 'column_name' => $column, 'ui_name' => $ui];
            $uis = to_name_value($requestPayload, $keys);

            $column_settings = [];
            foreach ($uis as $col) {
                $existing = $TableGateway->select(['table_name' => $table, 'column_name' => $column, 'ui_name' => $ui, 'name' => $col['name']])->toArray();
                if (count($existing) > 0) {
                    $col['id'] = $existing[0]['id'];
                }
                array_push($column_settings, $col);
            }
            $TableGateway->updateCollection($column_settings);
    }
    $UiOptions = new DirectusUiTableGateway($acl, $ZendDb);
    $response = $UiOptions->fetchOptions($table, $column, $ui);
    if (!$response) {
        $app->response()->setStatus(404);
        $response = [
            'message' => __t('unable_to_find_column_x_options_for_x', ['column' => $column, 'ui' => $ui]),
            'success' => false
        ];
    }

    return $app->response($response, ['table' => 'directus_ui']);
})->via('GET', 'POST', 'PUT');

$app->notFound(function () use ($app, $acl, $ZendDb) {
    $app->response()->header('Content-Type', 'text/html; charset=utf-8');

    $settingsTable = new DirectusSettingsTableGateway($acl, $ZendDb);
    $settings = $settingsTable->fetchCollection('global');

    $projectName = isset($settings['project_name']) ? $settings['project_name'] : 'Directus';
    $projectLogoURL = rtrim(DIRECTUS_PATH, '/') . '/assets/img/directus-logo-flat.svg';
    if (isset($settings['cms_thumbnail_url']) && $settings['cms_thumbnail_url']) {
        $projectLogoURL = $settings['cms_thumbnail_url'];
    }

    $data = [
        'project_name' => $projectName,
        'project_logo' => $projectLogoURL,
    ];

    $app->render('errors/404.twig.html', $data);
});

/**
 * Run the Router
 */

if (isset($_GET['run_api_router']) && $_GET['run_api_router']) {
    // Run Slim
    $app->response()->header('Content-Type', 'application/json; charset=utf-8');
    $app->run();
}
