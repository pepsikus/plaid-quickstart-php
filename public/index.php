<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

use \Plaid\Client as PlaidClient;
use \Plaid\PlaidException as PlaidException;

require '../vendor/autoload.php';

$config['displayErrorDetails'] = true;
$config['addContentLengthHeader'] = false;

$plaidConfig = [
	'environment' => getenv('PLAID_ENV') ? getenv('PLAID_ENV') : 'sandbox',
	'public_key' => getenv('PLAID_PUBLIC_KEY') ? getenv('PLAID_PUBLIC_KEY') : '',
    'client_id' => getenv('PLAID_CLIENT_ID') ? getenv('PLAID_CLIENT_ID') : '',
    'secret' => getenv('PLAID_SECRET') ? getenv('PLAID_SECRET') : '',
    'api_version' => getenv('PLAID_API_VERSION') ? getenv('PLAID_API_VERSION') : '2018-05-22',
];
$config['plaid'] = $plaidConfig;

$app = new \Slim\App(['settings' => $config]);
$app->add(new \Slim\Middleware\Session([
  'name' => 'dummy_session',
  'autorefresh' => true,
  'lifetime' => '1 hour'
]));

$container = $app->getContainer();

$container['session'] = function ($c) {
  return new \SlimSession\Helper;
};

$container['logger'] = function($c) {
    $logger = new \Monolog\Logger('my_logger');
    $file_handler = new \Monolog\Handler\StreamHandler('../logs/app.log');
    $logger->pushHandler($file_handler);
    return $logger;
};

$container['view'] = new \Slim\Views\PhpRenderer('../templates/');

$container['plaid'] = new PlaidClient($plaidConfig['client_id'], $plaidConfig['secret'], $plaidConfig['public_key'], $plaidConfig['environment'], $plaidConfig['api_version']);


$app->get('/', function (Request $request, Response $response, array $args) {
    $plaid_config = $this->get('settings')['plaid'];
    $this->logger->addInfo("plaid config:".json_encode($plaid_config));

    $response = $this->view->render($response, 'plaid.phtml', [
    	"plaid_config" => $plaid_config,
    ]);
    return $response;
});

/**
 * Exchange token flow - exchange a Link public_token for
 * an API access_token
 * https://plaid.com/docs/#exchange-token-flow
 */
$app->post('/get_access_token', function (Request $request, Response $response, array $args) {
    $params = $request->getParsedBody();
    $public_token = $params['public_token'];
    $this->logger->addInfo("get_access_token: input args:\n ".json_encode($params));

    try {
        $response = $this->plaid->item()->publicToken()->exchange($public_token);   
    } catch (PlaidException $e) {
        $this->logger->addError("get_access_token:\n ".print_r($e));
        return format_error($e);
    }
 
    $access_token = $response['access_token'];
    $item_id = $response['item_id'];

    $this->session->set('access_token', $access_token);
    $this->session->set('item_id', $item_id);

    $this->logger->addInfo("get_access_token: \n access_token=$access_token, item_id=$item_id");

    return json_encode([
        'access_token' => $access_token,
        'item_id' => $item_id,
        'error' => null
    ]);
});

/**
 * Rotate API access_token
 * https://plaid.com/docs/#rotate-access-token
 */
$app->post('/invalidate_access_token', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("identity: \n access_token=$access_token");

    try {
        $response = $this->plaid->item()->accessToken()->invalidate($access_token);
    } catch (PlaidException $e) {
        $this->logger->addError("invalidte a.token: \n ".$e->getMessage());
        return format_error($e);
    }

    $access_token = $response['new_access_token'];
    $this->session->set('access_token', $access_token);

    $this->logger->addInfo("invalidte a.token: \n \n".json_encode($response));

    return json_encode([
            'error' => null,
            'access_token' => $access_token
        ]);
});

/**
 * Set test Item's item_id and access_token from URL,
 * hack to avoid the Plaid Link authentication
 * http://your-test-app-url/?access_token=access-sandbox-xxx
 */
$app->post('/set_access_token', function (Request $request, Response $response, array $args) {
    $params = $request->getParsedBody();
    if (array_key_exists('access_token', $params) && $params['access_token']) {
        $access_token = $params['access_token'];
    } else {
        return json_encode([
            'error' => [
                'error_code' => 'MISSING FIELDS', 
                'error_type' => 'INVALID_REQUEST', 
                'error_message' => 'Access token is missing'
            ]
        ]);
    }

    $this->session->set('access_token', $access_token);

    $this->logger->addInfo("set_access_token: \n access_token=$access_token");

    try {
        $item_response = $this->plaid->item()->get($access_token);
    } catch (PlaidException $e) {
        $this->logger->addError("set_access_token: \n ".$e->getMessage());
        return format_error($e);   
    }

    $this->session->set('item_id', $item_response['item']['item_id']);

    $this->logger->addInfo("item: \n".json_encode($item_response));

    return json_encode([
            'error' => null,
            'item_id' => $item_response['item']['item_id']
        ]);
});

/**
 * Retrieve ACH or ETF account numbers for an Item
 * https://plaid.com/docs/#auth
 */
$app->get('/auth', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("auth: \n access_token=$access_token");

    try {
        $response = $this->plaid->auth()->get($access_token);
    } catch (PlaidException $e) {
        $this->logger->addError("auth: \n ".$e->getMessage());
        return format_error($e);
    }

    $this->logger->addInfo("auth: \n".json_encode($response));

    return json_encode([
            'error' => null,
            'auth'=> $response
        ]);
});

/**
 * Retrieve Transactions for an Item
 * https://plaid.com/docs/#transactions
 */
$app->get('/transactions', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("transactions: \n access_token=$access_token");

    $start_date = \DateTime::createFromFormat("Y-m-d", date("Y-m-d"))->sub(new \DateInterval('P30D'))->format("Y-m-d");
    $end_date = date("Y-m-d");

    try {
        $response = $this->plaid->transactions()->get($access_token, $start_date, $end_date);
    } catch (PlaidException $e) {
        $this->logger->addError("transactions: \n ".$e->getMessage());
        return format_error($e);
    }

    $this->logger->addInfo("transactions: \n".json_encode($response));

    return json_encode([
            'error' => null,
            'transactions'=> $response
        ]);
});

/**
 * Retrieve Identity data for an Item
 * https://plaid.com/docs/#identity
 */
$app->get('/identity', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("identity: \n access_token=$access_token");

    try {
        $response = $this->plaid->identity()->get($access_token);
    } catch (PlaidException $e) {
        $this->logger->addError("identity: \n ".$e->getMessage());
        return format_error($e);
    }

    $this->logger->addInfo("identity: \n".json_encode($response));

    return json_encode([
            'error' => null,
            'identity'=> $response
        ]);
});

/**
 * Retrieve real-time balance data for each of an Item's accounts
 * https://plaid.com/docs/#balance
 */
$app->get('/balance', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("balance: \n access_token=$access_token");

    try {
        $response = $this->plaid->balance()->get($access_token);
    } catch (PlaidException $e) {
        $this->logger->addError("balance: \n ".$e->getMessage());
        return format_error($e);
    }

    $this->logger->addInfo("balance: \n".json_encode($response));

    return json_encode([
            'error' => null,
            'balance'=> $response
        ]);
});

/**
 *  Retrieve high-level information about an Item
 * https://plaid.com/docs/#retrieve-item
 */
$app->get('/item', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("item: \n access_token=$access_token");

    try {
        $item_response = $this->plaid->item()->get($access_token);
        $institution_response = $this->plaid->institutions()->getById($item_response['item']['institution_id']);
    } catch (PlaidException $e) {
        $this->logger->addError("item: \n ".$e->getMessage());
        return format_error($e);   
    }

    $this->logger->addInfo("item: \n".json_encode($item_response));

    return json_encode([
            'error' => null,
            'item' => $item_response['item'],
            'institution' => $institution_response['institution']
        ]);
});

/**
 * Retrieve an Item's accounts
 * https://plaid.com/docs/#accounts
 */
$app->get('/accounts', function (Request $request, Response $response, array $args) {
    $access_token = $this->session->get('access_token');
    $this->logger->addInfo("accounts: \n access_token=$access_token");

    try {
        $response = $this->plaid->accounts()->get($access_token);
    } catch (PlaidException $e) {
        $this->logger->addError("accounts: \n ".$e->getMessage());
        return format_error($e);
    }

    $this->logger->addInfo("accounts: \n".json_encode($response));

    return json_encode([
            'error' => null,
            'accounts' => $response
        ]);
});

/**
 * Error formatting
 * @param  \Plaid\PlaidException $e
 * @return string  Error in JSON
 */
function format_error($e)
{    
    return json_encode([
        'error' => [
            'display_message' => $e->getDisplayMessage(), 
            'error_code' => $e->getCode(), 
            'error_type' => $e->getType(), 
            'error_message' => $e->getMessage()
            ]
        ]);
}


$app->run();
