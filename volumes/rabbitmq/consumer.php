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
        $uid = $this->sanitizeField($userNode->uid);
    
        if (empty($uid)) {
            throw new Exception("UID ontbreekt in bericht");
        }
    
        switch ($operation) {
            case 'create':
                $this->createUser($userNode, $sender);
                break;
            case 'update':
                $this->updateUser($uid, $userNode);
                break;
            case 'delete':
                $this->deleteUser($uid);
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
        $uid = $this->sanitizeField($userNode->uid); // <-- UID ophalen uit XML
        
        if (empty($uid)) {
            throw new Exception("UID is verplicht bij create operatie");
        }
    
        error_log("Received UID from message: $uid");
    
        // Check if user already exists by UID
        $checkUidQuery = "SELECT user_id FROM {$this->table_prefix}_usermeta WHERE meta_key = 'uid' AND meta_value = :uid LIMIT 1";
        $checkUidStmt = $this->db->prepare($checkUidQuery);
        $checkUidStmt->execute([':uid' => $uid]);
        $existingUserByUid = $checkUidStmt->fetch(PDO::FETCH_ASSOC);
    
        if ($existingUserByUid) {
            error_log("User with UID '$uid' already exists with ID: " . $existingUserByUid['user_id'] . ". Skipping creation.");
            return;
        }
    
        // Password ophalen
        $password = (string)$userNode->password;
        if (empty($password)) {
            throw new Exception("No password provided in user.register message from CRM/Odoo");
        }
    
        $hashed_activation_key = null;
    
        if ($sender == 'CRM' || $sender == 'Odoo') {
            $wp_host = getenv('WORDPRESS_HOST');
            $api_url = "http://wordpress:80/?rest_route=/myapiv2/set-activation-key";
            error_log("API URL: " . $api_url);
    
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
    
        if ($hashed_activation_key !== null) {
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
    
        // Insert user meta fields
        $metaFields = [
            'uid' => $uid, // <-- Extra: hier UID opslaan in usermeta
            'nickname' => $email,
            'first_name' => $this->sanitizeField($userNode->first_name),
            'last_name' => $this->sanitizeField($userNode->last_name),
            'birth_date' => $this->sanitizeField($userNode->date_of_birth ?? ''),
            'phone_number' => $this->sanitizeField($userNode->phone_number ?? ''),
            'account_status' => 'approved',
            'wp_capabilities' => 'a:1:{s:10:"subscriber";b:1;}'
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
    
        error_log("Created user with ID: $user_id, stored UID $uid, set role to 'subscriber' and account_status to approved");
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
   
 
    private function updateUser(string $uid, SimpleXMLElement $userNode) {
        $checkQuery = "SELECT u.ID 
        FROM {$this->table_prefix}_users u
        INNER JOIN {$this->table_prefix}_usermeta m ON u.ID = m.user_id
        WHERE m.meta_key = 'uid' AND m.meta_value = :uid";

        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':uid' => $uid]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            throw new Exception("Gebruiker met UID $uid niet gevonden");
        }
    
        $userId = $user['ID'];
        $email = $this->sanitizeField($userNode->email);
    
        $query = "UPDATE {$this->table_prefix}_users
                  SET user_email = :user_email, user_login = :user_login";
        $params = [
            ':user_email' => $email,
            ':user_login' => $email,
        ];
    
        if (isset($userNode->password) && !empty($userNode->password)) {
            $password = (string)$userNode->password;
            $query .= ", user_pass = :user_pass";
            $params[':user_pass'] = $password;
            error_log("Received password for user update UID $uid: $password");
        }
    
        $query .= " WHERE ID = :user_id";
        $params[':user_id'] = $userId;
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
    
        error_log("Updated user with UID: $uid (ID: $userId)");
    }
    
 
    private function deleteUser(string $uid) {
        $checkQuery = "SELECT u.ID 
        FROM {$this->table_prefix}_users u
        INNER JOIN {$this->table_prefix}_usermeta m ON u.ID = m.user_id
        WHERE m.meta_key = 'uid' AND m.meta_value = :uid";

        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':uid' => $uid]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
        if (!$user) {
            throw new Exception("Gebruiker met UID $uid niet gevonden");
        }
    
        $userId = $user['ID'];
    
        $deleteMeta = "DELETE FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id";
        $stmtMeta = $this->db->prepare($deleteMeta);
        $stmtMeta->execute([':user_id' => $userId]);
    
        $deleteUser = "DELETE FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $stmtUser = $this->db->prepare($deleteUser);
        $stmtUser->execute([':user_id' => $userId]);
    
        error_log("Deleted user with UID: $uid (ID: $userId)");
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