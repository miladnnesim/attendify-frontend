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
            'integrationproject-2425s2-002.westeurope.cloudapp.azure.com', # naam container
            getenv('RABBITMQ_AMQP_PORT'),
            getenv('RABBITMQ_USER'),
            getenv('RABBITMQ_PASSWORD'),# mogelijk dat de host en user door elkaar zijn
            getenv('RABBITMQ_USER')
        );
        $this->channel = $this->connection->channel();
        #$this->channel->exchange_declare($this->exchange, 'direct', false, true, false);
    }

    public function sendUserData($user_id, $operation = 'create') {
        global $wpdb; // Access WordPress database

        // Determine routing key based on operation
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

        // Build XML
        $xml = new SimpleXMLElement('<attendify/>');
        $info = $xml->addChild('info');
        $info->addChild('sender', 'frontend');
        $info->addChild('operation', $operation);

        $user_node = $xml->addChild('user');
        $user_node->addChild('id', $user_id);

        // For delete operation, we only need the user ID
        
            // Fetch user data from wp_users
            $user = get_userdata($user_id);
            if (!$user) {
                throw new Exception("User with ID $user_id not found.");
            }
            $user_email = $user->user_email;
            $bcrypt_hash = $user->user_pass; // Haal de bestaande hash op (bcrypt of phpass)

            // Debug: Log user email en hash
            error_log("User Email for ID $user_id: " . $user_email);
            error_log("Password Hash for ID $user_id: " . $bcrypt_hash);

            // Fetch from wp_usermeta
            $birth_date = get_user_meta($user_id, 'birth_date', true);
            $phone_number = get_user_meta($user_id, 'phone_number', true);

            // Debug: Log usermeta
            error_log("Birth Date for ID $user_id: " . ($birth_date ?: 'Not found'));
            error_log("Phone Number for ID $user_id: " . ($phone_number ?: 'Not found'));

            // Fetch from wp_um_metadata
            $um_table = $wpdb->prefix . 'um_metadata';
            $um_metadata = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT um_key, um_value FROM $um_table WHERE user_id = %d",
                    $user_id
                ),
                ARRAY_A
            );

            // Debug: Log UM metadata
            error_log("UM Metadata for ID $user_id: " . print_r($um_metadata, true));

            // Map UM metadata to variables
            $um_data = [];
            if (!empty($um_metadata)) {
                foreach ($um_metadata as $meta) {
                    $um_data[$meta['um_key']] = $meta['um_value'];
                }
            } else {
                error_log("No UM metadata found for user ID $user_id");
            }

            // Debug: Log mapped UM data
            error_log("Mapped UM Data for ID $user_id: " . print_r($um_data, true));

            // Extract required fields
            $first_name = $um_data['first_name'] ?? '';
            $last_name = $um_data['last_name'] ?? '';
            $title = $um_data['user_title'] ?? '';
            $street = $um_data['street_name'] ?? '';
            $bus_number = $um_data['bus_nr'] ?? '';
            $city = $um_data['city'] ?? '';
            $province = $um_data['province'] ?? '';
            $country = $um_data['user_country'] ?? '';
            $vat_number = $um_data['vat_number'] ?? '';

            // Add user details to XML
            $user_node->addChild('first_name', htmlspecialchars($first_name));
            $user_node->addChild('last_name', htmlspecialchars($last_name));
            $user_node->addChild('date_of_birth', htmlspecialchars($birth_date));
            $user_node->addChild('phone_number', htmlspecialchars($phone_number));
            $user_node->addChild('title', htmlspecialchars($title));
            $user_node->addChild('email', htmlspecialchars($user_email));
            $user_node->addChild('password', htmlspecialchars($bcrypt_hash)); // Stuur de bestaande hash

            // Address
            $address = $user_node->addChild('address');
            $address->addChild('street', htmlspecialchars($street));
            $address->addChild('number', ''); // Not provided in DB
            $address->addChild('bus_number', htmlspecialchars($bus_number));
            $address->addChild('city', htmlspecialchars($city));
            $address->addChild('province', htmlspecialchars($province));
            $address->addChild('country', htmlspecialchars($country));
            $address->addChild('postal_code', ''); // Not provided in DB

            // Payment Details (minimal, as most data isn't available)
            $payment_details = $user_node->addChild('payment_details');
            $facturation_address = $payment_details->addChild('facturation_address');
            $facturation_address->addChild('street', htmlspecialchars($street)); // Same as user address
            $facturation_address->addChild('number', '');
            $facturation_address->addChild('company_bus_number', htmlspecialchars($bus_number));
            $facturation_address->addChild('city', htmlspecialchars($city));
            $facturation_address->addChild('province', htmlspecialchars($province));
            $facturation_address->addChild('country', htmlspecialchars($country));
            $facturation_address->addChild('postal_code', '');
            $payment_details->addChild('payment_method', '');
            $payment_details->addChild('card_number', '');

            $user_node->addChild('email_registered', 'true');

            // Company
            $company = $user_node->addChild('company');
            $company->addChild('id', '');
            $company->addChild('name', '');
            $company->addChild('VAT_number', htmlspecialchars($vat_number));
            $company_address = $company->addChild('address');
            $company_address->addChild('street', '');
            $company_address->addChild('number', '');
            $company_address->addChild('city', '');
            $company_address->addChild('province', '');
            $company_address->addChild('country', '');
            $company_address->addChild('postal_code', '');

            $user_node->addChild('from_company', 'false');
        

        // Convert to string
        $xml_message = $xml->asXML();

        // Controleer op dubbele boodschap
        $hash = md5($xml_message);
        $transient_key = "last_message_hash_user_{$user_id}_{$operation}";
        $last_hash = get_transient($transient_key);

        if ($last_hash === $hash) {
            error_log("Overslaan van dubbele boodschap voor gebruiker $user_id met operatie $operation");
            return;
        }

        // Verstuur de boodschap
        $msg = new AMQPMessage($xml_message);
        $this->channel->basic_publish($msg, $this->exchange, $routing_key);

        // Sla de hash op in de transient (5 seconden)
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
    $user_id = $argv[1] ?? 1; // Pass user ID as argument
    $operation = $argv[2] ?? 'create';
    $producer = new Producer();
    $producer->sendUserData($user_id, $operation);
}