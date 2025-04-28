<?php
require_once '/var/www/html/vendor/autoload.php';
 
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
 
class RabbitMQ_Consumer {
    private $connection;
    private $channel;
    private $db;
    private $exchange = 'user-management';
    private $queue = 'frontend.user';
    private $table_prefix = 'wp';
 
    public function __construct() {
        $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
        try {
            $this->db = new PDO($dsn, getenv('LOCAL_DB_USER'), getenv('LOCAL_DB_PASSWORD'));
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("Successfully connected to database");
        } catch (PDOException $e) {
            error_log("Failed to connect to database: " . $e->getMessage());
            exit(1);
        }
 
        $this->connectRabbitMQ();
        $this->setupQueue();
        $this->processMessages();
    }
   
 
    private function connectRabbitMQ() {
        $maxRetries = 5;
        $retryDelay = 3;
 
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $this->connection = new AMQPStreamConnection(
                    'rabbitmq', # naam container
                    getenv('RABBITMQ_AMQP_PORT'),
                    getenv('RABBITMQ_HOST'),
                    getenv('RABBITMQ_PASSWORD'),# mogelijk dat de host en user door elkaar zijn
                    getenv('RABBITMQ_USER')
                );
                $this->channel = $this->connection->channel();
                error_log("Successfully connected to RabbitMQ");
                return;
            } catch (AMQPIOException $e) {
                if ($i === $maxRetries - 1) {
                    error_log("Kon geen verbinding maken met RabbitMQ: " . $e->getMessage());
                    throw $e;
                }
                error_log("Retrying RabbitMQ connection ($i/$maxRetries)...");
                sleep($retryDelay);
            }
        }
    }
 
    private function setupQueue() {
        // Queue setup logic (unchanged)
    }
 
    private function processMessages() {
        $callback = function (AMQPMessage $msg) {
            try {
                $xml = simplexml_load_string($msg->body);
                if (!$xml) {
                    throw new Exception("Ongeldig XML-formaat");
                }
       
                $sender = (string)$xml->info->sender;
                if (strtolower($sender) === 'frontend') {
                    $msg->ack();
                    return;
                }
       
                // Pass $sender to handleMessage
                $this->handleMessage($msg, $sender);
                $msg->ack();
            } catch (Exception $e) {
                error_log("[ERROR] " . $e->getMessage());
                $msg->nack(false, true);
            }
        };
       
 
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume($this->queue, '', false, false, false, false, $callback);
 
        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }
 
    private function handleMessage(AMQPMessage $msg, $sender) {
        $xml = simplexml_load_string($msg->body);
        if (!$xml) {
            throw new Exception("Ongeldig XML-formaat");
        }
   
        $operation = (string)$xml->info->operation;
        $userNode = $xml->user;
        $email = $this->sanitizeField($userNode->email);
   
        switch ($operation) {
            case 'create':
                $this->createUser($userNode, $sender);
                break;
            case 'update':
                $this->updateUser($email, $userNode);
                break;
            case 'delete':
                $this->deleteUser($email);
                break;
            default:
                throw new Exception("Onbekende operatie: $operation");
        }
    }
    private function sendToMailingQueue($messageData) {
        $exchange = 'user-management';
        $queue = 'mailing.mail';
        $routing_key = 'user.passwordReset';
   
   
        // Publish the message
        $message = new AMQPMessage($messageData);
        $this->channel->basic_publish($message, $exchange, $routing_key);
                error_log("Sent activation link to mailing.mail queue for user {$messageData}");
    }
 
    private function createUser(SimpleXMLElement $userNode, $sender) {
        $email = $this->sanitizeField($userNode->email);
        $display_name = $this->sanitizeField($userNode->first_name . ' ' . $userNode->last_name);
        $userIdFromMessage = (int)$userNode->id;
   
        error_log("Received user ID from message: $userIdFromMessage");
   
        // Check if user already exists by email
        $checkEmailQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_email = :user_email LIMIT 1";
        $checkEmailStmt = $this->db->prepare($checkEmailQuery);
        $checkEmailStmt->execute([':user_email' => $email]);
        $existingUserByEmail = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);
   
        if ($existingUserByEmail) {
            error_log("User with email '$email' already exists with ID: " . $existingUserByEmail['ID'] . ". Skipping creation.");
            return;
        }
   
        // Check if user ID already exists
        if ($userIdFromMessage > 0) {
            $checkIdQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE ID = :user_id LIMIT 1";
            $checkIdStmt = $this->db->prepare($checkIdQuery);
            $checkIdStmt->execute([':user_id' => $userIdFromMessage]);
            $existingUserById = $checkIdStmt->fetch(PDO::FETCH_ASSOC);
   
            if ($existingUserById) {
                error_log("User with ID '$userIdFromMessage' already exists. Skipping creation.");
                return;
            }
        }
   
        // Password from CRM/Odoo
        // Password ophalen
    $password = (string)$userNode->password;
    if (empty($password)) {
       throw new Exception("No password provided in user.register message from CRM/Odoo");
    }
 
    $hashed_activation_key = null; // Alleen vullen als nodig
 
    if ($sender == 'CRM' || $sender == 'Odoo') {
        $wp_host = getenv('WORDPRESS_HOST') ?: 'Localhost:30025';
        $api_url = $wp_host . "/?rest_route=/myapiv2/set-activation-key";
     error_log("api url :" . $api_url);
 
     $shared_secret = getenv('MY_API_SHARED_SECRET');
     if (!$shared_secret) {
         error_log('Shared secret not set in environment variables!');
            throw new Exception('Shared secret not configured');
      }
 
     $random_bytes = random_bytes(16);
        $activation_key = bin2hex($random_bytes);
 
        $data = json_encode(['activation_key' => $activation_key]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
           'X-Shared-Secret: ' . $shared_secret,
           'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
        error_log('cURL error: ' . curl_error($ch));
        throw new Exception('cURL error: ' . curl_error($ch));
        }
        curl_close($ch);
 
        $response_data = json_decode($response, true);
        if (isset($response_data['hashed_activation_key'])) {
        $hashed_activation_key = $response_data['hashed_activation_key'];
        $this->sendToMailingQueue($wp_host . "/wp-login.php?action=rp&key=" . $activation_key . "&login=" . rawurlencode($email));
        echo 'Hashed Activation Key: ' . $hashed_activation_key;
        } else {
        error_log('No hashed activation key found in response');
        throw new Exception('Failed to retrieve hashed activation key');
        }
    }
 
// Nu controleren of je een activation key hebt of niet
    if ($hashed_activation_key !== null) {
    // INSERT MET activation key
        $query = "INSERT INTO {$this->table_prefix}_users
        (user_login, user_pass, user_email, user_registered, display_name, user_activation_key)
        VALUES (:user_login, :user_pass, :user_email, NOW(), :display_name, :user_activation_key)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
        ':user_login' => $email,
        ':user_pass' => $password,
        ':user_email' => $email,
        ':display_name' => $display_name,
        ':user_activation_key' => $hashed_activation_key
        ]);
    } else {
    // INSERT ZONDER activation key
        $query = "INSERT INTO {$this->table_prefix}_users
        (user_login, user_pass, user_email, user_registered, display_name)
        VALUES (:user_login, :user_pass, :user_email, NOW(), :display_name)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
        ':user_login' => $email,
        ':user_pass' => $password,
        ':user_email' => $email,
        ':display_name' => $display_name
        ]);
    }
 
        $user_id = $this->db->lastInsertId();
        error_log("Inserted user with auto-increment ID: $user_id");
 
   
        // Check if the sender is CRM or Odoo and call the WordPress API
       
   
        // Insert user meta fields
        $metaFields = [
            'nickname' => $email,
            'first_name' => $this->sanitizeField($userNode->first_name),
            'last_name' => $this->sanitizeField($userNode->last_name),
            'birth_date' => $this->sanitizeField($userNode->date_of_birth ?? ''),
            'phone_number' => $this->sanitizeField($userNode->phone_number ?? ''),
            'account_status' => 'approved',
            'wp_capabilities' => 'a:1:{s:10:\"subscriber\";b:1;}'
        ];
   
        $metaQuery = "INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value)
                      VALUES (:user_id, :meta_key, :meta_value)";
        $metaStmt = $this->db->prepare($metaQuery);
   
        foreach ($metaFields as $key => $value) {
            if (!empty($key)) {
                $metaStmt->execute([
                    ':user_id' => $user_id,
                    ':meta_key' => $key,
                    ':meta_value' => $value
                ]);
            }
        }
   
        error_log("Created user with ID: $user_id, set role to 'subscriber' and account_status to approved");
    }
   
    private function makeHttpRequest($url, $args) {
        $ch = curl_init($url);
   
        // Set curl options
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $args['method']);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function($key, $value) {
            return "$key: $value";
        }, array_keys($args['headers']), $args['headers']));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $args['body']);
        curl_setopt($ch, CURLOPT_TIMEOUT, $args['timeout']);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
   
        // Execute request
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
   
        if ($response === false) {
            return ['error' => 'cURL error: ' . $error];
        }
   
        if ($http_code >= 400) {
            return ['error' => 'HTTP error: ' . $http_code . ' - ' . $response];
        }
   
        return json_decode($response, true);
    }
   
 
    private function updateUser(string $email, SimpleXMLElement $userNode) {
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_email = :user_email";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_email' => $email]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception("Gebruiker met e-mail $email niet gevonden");
        }
 
        $userId = $user['ID'];
 
        $query = "UPDATE {$this->table_prefix}_users
                  SET user_email = :user_email, user_login = :user_login";
        $params = [
            ':user_email' => $email,
            ':user_login' => $email,
            ':user_email_where' => $email
        ];
 
        if (isset($userNode->password) && !empty($userNode->password)) {
            $password = (string)$userNode->password;
            $query .= ", user_pass = :user_pass";
            $params[':user_pass'] = $password;
            error_log("Received password for user update email $email: $password");
        }
 
        $query .= " WHERE user_email = :user_email_where";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
 
        $updateFields = [
            'first_name' => $this->sanitizeField($userNode->first_name),
            'last_name' => $this->sanitizeField($userNode->last_name),
            'phone_number' => $this->sanitizeField($userNode->phone_number),
            'birth_date' => $this->sanitizeField($userNode->date_of_birth),
            'user_title' => $this->sanitizeField($userNode->title),
            'street_name' => $this->sanitizeField($userNode->address->street ?? ''),
            'bus_nr' => $this->sanitizeField($userNode->address->bus_number ?? ''),
            'city' => $this->sanitizeField($userNode->address->city ?? ''),
            'province' => $this->sanitizeField($userNode->address->province ?? ''),
            'user_country' => $this->sanitizeField($userNode->address->country ?? ''),
            'vat_number' => $this->sanitizeField($userNode->company->VAT_number ?? '')
        ];
 
        $metaQuery = "INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value)
                      VALUES (:user_id, :meta_key, :meta_value)
                      ON DUPLICATE KEY UPDATE meta_value = :meta_value";
        $metaStmt = $this->db->prepare($metaQuery);
 
        foreach ($updateFields as $key => $value) {
            if ($value !== '' && $value !== null) {
                $metaStmt->execute([
                    ':user_id' => $userId,
                    ':meta_key' => $key,
                    ':meta_value' => $value
                ]);
            }
        }
 
        $umQuery = "INSERT INTO {$this->table_prefix}_um_metadata (user_id, um_key, um_value)
                    VALUES (:user_id, :um_key, :um_value)
                    ON DUPLICATE KEY UPDATE um_value = :um_value";
        $umStmt = $this->db->prepare($umQuery);
        foreach ($updateFields as $key => $value) {
            if ($value !== '' && $value !== null) {
                $umStmt->execute([
                    ':user_id' => $userId,
                    ':um_key' => $key,
                    ':um_value' => $value
                ]);
            }
        }
 
        error_log("Updated user with email: $email (ID: $userId)");
    }
 
    private function deleteUser(string $email) {
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_email = :user_email";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_email' => $email]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception("Gebruiker met e-mail $email niet gevonden");
        }
 
        $userId = $user['ID'];
 
        $query = "DELETE FROM {$this->table_prefix}_users WHERE user_email = :user_email";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_email' => $email]);
 
        $metaQuery = "DELETE FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id";
        $metaStmt = $this->db->prepare($metaQuery);
        $metaStmt->execute([':user_id' => $userId]);
 
        $umQuery = "DELETE FROM {$this->table_prefix}_um_metadata WHERE user_id = :user_id";
        $umStmt = $this->db->prepare($umQuery);
        $umStmt->execute([':user_id' => $userId]);
 
        error_log("Deleted user with email: $email (ID: $userId)");
    }
 
    private function sanitizeField($value) {
        return htmlspecialchars(strip_tags((string)$value));
    }
}
 
 
 
try {
    new RabbitMQ_Consumer();
} catch (Exception $e) {
    error_log("Consumer failed to start: " . $e->getMessage());
    exit(1);
}