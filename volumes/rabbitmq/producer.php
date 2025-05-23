<?php
// Load WordPress environment if not already loaded
if (!function_exists('get_userdata')) {
    $wp_load_path = dirname(__DIR__) . '/wp-load.php';
    if (file_exists($wp_load_path)) {
        require_once $wp_load_path;
    } else {
        die("Error: Could not load WordPress environment. Ensure this script is run within a WordPress installation.\n");
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
            case 'create':
                $routing_key = 'user.register';
                break;
            case 'update':
                $routing_key = 'user.update';
                break;
            case 'delete':
                $routing_key = 'user.delete';
                break;
            default:
                throw new Exception("Invalid operation: $operation");
        }

        $user = get_userdata($user_id);
        if (!$user) {
            throw new Exception("User with ID $user_id not found.");
        }

        $customUserId = get_user_meta($user_id, 'uid', true);
        if (!$customUserId) {
            $servicePrefix = 'WP';
            $customUserId = $servicePrefix . time();
            update_user_meta($user_id, 'uid', $customUserId);
        }

        // 🧠 Metadata ophalen
        $birth_date = get_user_meta($user_id, 'birth_date', true);
        $phone_number = get_user_meta($user_id, 'phone_number', true);
        $old_company_vat_number = get_user_meta($user_id, 'old_company_vat_number', true);

        $um_table = $wpdb->prefix . 'um_metadata';
        $um_metadata = $wpdb->get_results(
            $wpdb->prepare("SELECT um_key, um_value FROM $um_table WHERE user_id = %d", $user_id),
            ARRAY_A
        );

        $um_data = [];
        if (!empty($um_metadata)) {
            foreach ($um_metadata as $meta) {
                $um_data[$meta['um_key']] = $meta['um_value'];
            }
        }

        $first_name  = $um_data['first_name']    ?? get_user_meta($user_id, 'first_name', true);
        $last_name   = $um_data['last_name']     ?? get_user_meta($user_id, 'last_name', true);
        $title       = $um_data['user_title']    ?? get_user_meta($user_id, 'user_title', true);
        $street      = $um_data['street_name']   ?? get_user_meta($user_id, 'street_name', true);
        $bus_number  = $um_data['bus_nr']        ?? get_user_meta($user_id, 'bus_nr', true);
        $city        = $um_data['city']          ?? get_user_meta($user_id, 'city', true);
        $province    = $um_data['province']      ?? get_user_meta($user_id, 'province', true);
        $country     = $um_data['user_country']  ?? get_user_meta($user_id, 'user_country', true);
        $company_uid = $um_data['company_vat_number'] ?? get_user_meta($user_id, 'company_vat_number', true); // 💡 company UID

        // ✅ Company-link check
        if ($company_uid !== $old_company_vat_number) {
            require_once __DIR__ . '/producer_user_link_company.php';

            if (!empty($old_company_vat_number)) {
                sendUserCompanyLink($customUserId, $old_company_vat_number, 'unregister');
                error_log("❌ Ontkoppeld: user {$customUserId} van oud bedrijf {$old_company_vat_number}");
            }

            if (!empty($company_uid)) {
                sendUserCompanyLink($customUserId, $company_uid, 'register');
                error_log("✅ Gekoppeld: user {$customUserId} aan nieuw bedrijf {$company_uid}");
                update_user_meta($user_id, 'old_company_vat_number', $company_uid);
            } else {
                delete_user_meta($user_id, 'old_company_vat_number');
            }
        }

        // XML opbouwen
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

        $address = $user_node->addChild('address');
        $address->addChild('street', htmlspecialchars($street));
        $address->addChild('number', '');
        $address->addChild('bus_number', htmlspecialchars($bus_number));
        $address->addChild('city', htmlspecialchars($city));
        $address->addChild('province', htmlspecialchars($province));
        $address->addChild('country', htmlspecialchars($country));
        $address->addChild('postal_code', '');

        $payment_details = $user_node->addChild('payment_details');
        $facturation_address = $payment_details->addChild('facturation_address');
        $facturation_address->addChild('street', htmlspecialchars($street));
        $facturation_address->addChild('number', '');
        $facturation_address->addChild('company_bus_number', htmlspecialchars($bus_number));
        $facturation_address->addChild('city', htmlspecialchars($city));
        $facturation_address->addChild('province', htmlspecialchars($province));
        $facturation_address->addChild('country', htmlspecialchars($country));
        $facturation_address->addChild('postal_code', '');
        $payment_details->addChild('payment_method', '');
        $payment_details->addChild('card_number', '');

        $user_node->addChild('email_registered', 'true');

        $company = $user_node->addChild('company');
        $company->addChild('id', '');
        $company->addChild('name', '');
        $company->addChild('VAT_number', htmlspecialchars($company_uid));
        $company_address = $company->addChild('address');
        $company_address->addChild('street', '');
        $company_address->addChild('number', '');
        $company_address->addChild('city', '');
        $company_address->addChild('province', '');
        $company_address->addChild('country', '');
        $company_address->addChild('postal_code', '');

        $user_node->addChild('from_company', 'false');

        $dom = new DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $xml_message = $dom->saveXML();

        // Dubbele boodschap check
        $hash = md5($xml_message);
        $transient_key = "last_message_hash_user_{$user_id}_{$operation}";
        $last_hash = get_transient($transient_key);

        if ($last_hash === $hash) {
            error_log("Overslaan van dubbele boodschap voor gebruiker $user_id met operatie $operation");
            return;
        }

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

// For manual testing
if (php_sapi_name() === 'cli') {
    $user_id = $argv[1] ?? 1;
    $operation = $argv[2] ?? 'create';
    $producer = new Producer();
    $producer->sendUserData($user_id, $operation);
}
