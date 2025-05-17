<?php
if (!function_exists('get_userdata')) {
    $wp_load_path = dirname(__DIR__) . '/wp-load.php';
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        die("Error: Could not load WordPress environment.\n");
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Producer {
    private $connection;
    private $channel;
    private $exchange = 'user-management';

    public function __construct() {
        $this->connection = new AMQPStreamConnection(
            'rabbitmq',
            getenv('RABBITMQ_AMQP_PORT'),
            getenv('RABBITMQ_HOST'),
            getenv('RABBITMQ_PASSWORD'),
            getenv('RABBITMQ_USER')
        );
        $this->channel = $this->connection->channel();
    }

    public function sendUserData($user_id, $operation = 'create') {
        global $wpdb;

        switch ($operation) {
            case 'create': $routing_key = 'user.register'; break;
            case 'update': $routing_key = 'user.update'; break;
            case 'delete': $routing_key = 'user.delete'; break;
            default: throw new Exception("Invalid operation: $operation");
        }

        $user = get_userdata($user_id);
        if (!$user) throw new Exception("User with ID $user_id not found.");

        $customUserId = get_user_meta($user_id, 'uid', true);
        if (!$customUserId) {
            $customUserId = 'WP' . time();
            update_user_meta($user_id, 'uid', $customUserId);
        }

        $is_admin = user_can($user_id, 'administrator') ? 'true' : 'false';
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        $birth_date = get_user_meta($user_id, 'birth_date', true);
        $phone_number = get_user_meta($user_id, 'phone_number', true);
        $title = get_user_meta($user_id, 'user_title', true);
        $street = get_user_meta($user_id, 'street_name', true);
        $bus_number = get_user_meta($user_id, 'bus_nr', true);
        $city = get_user_meta($user_id, 'city', true);
        $province = get_user_meta($user_id, 'province', true);
        $country = get_user_meta($user_id, 'user_country', true);
        $company_uid = get_user_meta($user_id, 'company_vat_number', true);

        $xml = new SimpleXMLElement('<attendify/>');
        $info = $xml->addChild('info');
        $info->addChild('sender', 'frontend');
        $info->addChild('operation', $operation);

        $user_node = $xml->addChild('user');
        $user_node->addChild('uid', $customUserId);
        $user_node->addChild('first_name', htmlspecialchars($first_name));
        $user_node->addChild('last_name', htmlspecialchars($last_name));
        $user_node->addChild('date_of_birth', htmlspecialchars($birth_date));
        $user_node->addChild('phone_number', htmlspecialchars($phone_number));
        $user_node->addChild('title', htmlspecialchars($title));
        $user_node->addChild('email', htmlspecialchars($user->user_email));
        $user_node->addChild('password', htmlspecialchars($user->user_pass));
        $user_node->addChild('is_admin', $is_admin);

        $address = $user_node->addChild('address');
        $address->addChild('street', htmlspecialchars($street));
        $address->addChild('number', '');
        $address->addChild('bus_number', htmlspecialchars($bus_number));
        $address->addChild('city', htmlspecialchars($city));
        $address->addChild('province', htmlspecialchars($province));
        $address->addChild('country', htmlspecialchars($country));
        $address->addChild('postal_code', '');

        $company = $user_node->addChild('company');
        $company->addChild('VAT_number', htmlspecialchars($company_uid));

        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $xml_message = $dom->saveXML();

        $hash = md5($xml_message);
        $transient_key = "last_message_hash_user_{$user_id}_{$operation}";
        if (get_transient($transient_key) === $hash) return;

        $msg = new AMQPMessage($xml_message);
        $this->channel->basic_publish($msg, $this->exchange, $routing_key);

        set_transient($transient_key, $hash, 5);
        echo "[x] Sent XML message with routing key '$routing_key'\n";
    }

    public function __destruct() {
        $this->channel->close();
        $this->connection->close();
    }
}

if (php_sapi_name() === 'cli') {
    $user_id = $argv[1] ?? 1;
    $operation = $argv[2] ?? 'create';
    $producer = new Producer();
    $producer->sendUserData($user_id, $operation);
}
