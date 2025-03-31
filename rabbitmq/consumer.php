<?php
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;

// Standalone phpass-compatibele wachtwoord hashing (gebaseerd op WordPress)


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
        $this->channel->exchange_declare($this->exchange, 'direct', false, true, false);
        $this->channel->queue_declare($this->queue, false, true, false, false);

        $routingKeys = ['user.register', 'user.update', 'user.delete'];
        foreach ($routingKeys as $rk) {
            $this->channel->queue_bind($this->queue, $this->exchange, $rk);
        }
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

                $this->handleMessage($msg);
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

    private function handleMessage(AMQPMessage $msg) {
        $xml = simplexml_load_string($msg->body);
        if (!$xml) {
            throw new Exception("Ongeldig XML-formaat");
        }

        $operation = (string)$xml->info->operation;
        $userNode = $xml->user;
        $userId = (int)$userNode->id;

        switch ($operation) {
            case 'create':
                $this->createUser($userNode);
                break;
            case 'update':
                $this->updateUser($userId, $userNode);
                break;
            case 'delete':
                $this->deleteUser($userId);
                break;
            default:
                throw new Exception("Onbekende operatie: $operation");
        }
    }

    private function createUser(SimpleXMLElement $userNode) {
        $email = $this->sanitizeField($userNode->email);
        $display_name = $this->sanitizeField($userNode->first_name . ' ' . $userNode->last_name);
        $userIdFromMessage = (int)$userNode->id;
    
        error_log("Received user ID from message: $userIdFromMessage");
    
        $checkEmailQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_email = :user_email LIMIT 1";
        $checkEmailStmt = $this->db->prepare($checkEmailQuery);
        $checkEmailStmt->execute([':user_email' => $email]);
        $existingUserByEmail = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);
    
        if ($existingUserByEmail) {
            error_log("User with email '$email' already exists with ID: " . $existingUserByEmail['ID'] . ". Skipping creation.");
            return;
        }
    
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
    
        // Neem het <password>-veld direct over uit de XML, alsof het een gewone string is
        if (isset($userNode->password) && !empty($userNode->password)) {
            $password = (string)$userNode->password;
            error_log("Received password for user $email: $password");
        } else {
            throw new Exception("No password provided in user.register message from Fosbilling");
        }
    
        if ($userIdFromMessage > 0) {
            $query = "INSERT INTO {$this->table_prefix}_users 
                      (ID, user_login, user_pass, user_email, user_registered, display_name)
                      VALUES (:user_id, :user_login, :user_pass, :user_email, NOW(), :display_name)";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':user_id' => $userIdFromMessage,
                ':user_login' => $email,
                ':user_pass' => $password, // Direct de string uit <password> opslaan
                ':user_email' => $email,
                ':display_name' => $display_name
            ]);
            $user_id = $userIdFromMessage;
            error_log("Inserted user with specified ID: $user_id");
        } else {
            $query = "INSERT INTO {$this->table_prefix}_users 
                      (user_login, user_pass, user_email, user_registered, display_name)
                      VALUES (:user_login, :user_pass, :user_email, NOW(), :display_name)";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':user_login' => $email,
                ':user_pass' => $password, // Direct de string uit <password> opslaan
                ':user_email' => $email,
                ':display_name' => $display_name
            ]);
            $user_id = $this->db->lastInsertId();
            error_log("Inserted user with auto-increment ID: $user_id");
        }
    
        // Metadata
        $metaFields = [
            'nickname' => $email,
            'first_name' => $this->sanitizeField($userNode->first_name),
            'last_name' => $this->sanitizeField($userNode->last_name),
            'birth_date' => $this->sanitizeField($userNode->date_of_birth ?? ''),
            'phone_number' => $this->sanitizeField($userNode->phone_number ?? ''),
            'account_status' => 'approved',
            'wp_capabilities' => 'a:1:{s:10:"subscriber";b:1;}'
        ];
    
        $umFields = [
            'user_title' => $this->sanitizeField($userNode->title ?? ''),
            'street_name' => $this->sanitizeField($userNode->address->street ?? ''),
            'bus_nr' => $this->sanitizeField($userNode->address->bus_number ?? ''),
            'city' => $this->sanitizeField($userNode->address->city ?? ''),
            'province' => $this->sanitizeField($userNode->address->province ?? ''),
            'user_country' => $this->sanitizeField($userNode->address->country ?? ''),
            'vat_number' => $this->sanitizeField($userNode->company->VAT_number ?? '')
        ];
    
        $allFields = array_merge($metaFields, $umFields);
        $metaQuery = "INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value) 
                      VALUES (:user_id, :meta_key, :meta_value)";
        $metaStmt = $this->db->prepare($metaQuery);
    
        foreach ($allFields as $key => $value) {
            if (empty($key)) {
                error_log("Skipping empty meta_key for user $user_id");
                continue;
            }
            if ($value !== '' && $value !== null) {
                error_log("Adding meta for user $user_id: $key = $value");
                $metaStmt->execute([
                    ':user_id' => $user_id,
                    ':meta_key' => $key,
                    ':meta_value' => $value
                ]);
            } else {
                error_log("Skipping empty meta_value for user $user_id: $key");
            }
        }
    
        $umQuery = "INSERT INTO {$this->table_prefix}_um_metadata (user_id, um_key, um_value) 
                    VALUES (:user_id, :um_key, :um_value)";
        $umStmt = $this->db->prepare($umQuery);
        foreach ($umFields as $key => $value) {
            if (empty($key)) {
                error_log("Skipping empty UM meta_key for user $user_id");
                continue;
            }
            if ($value !== '' && $value !== null) {
                $umStmt->execute([
                    ':user_id' => $user_id,
                    ':um_key' => $key,
                    ':um_value' => $value
                ]);
            }
        }
    
        error_log("Created user with ID: $user_id, set role to 'subscriber' and account_status to approved");
    }

    private function updateUser(int $userId, SimpleXMLElement $userNode) {
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_id' => $userId]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Gebruiker $userId niet gevonden");
        }
    
        $email = $this->sanitizeField($userNode->email);
    
        // Basisquery voor email en login
        $query = "UPDATE {$this->table_prefix}_users 
                  SET user_email = :user_email, user_login = :user_login";
        $params = [
            ':user_email' => $email,
            ':user_login' => $email,
            ':user_id' => $userId
        ];
    
        // Controleer of er een wachtwoord is meegegeven en voeg het toe aan de update
        if (isset($userNode->password) && !empty($userNode->password)) {
            $password = (string)$userNode->password;
            $query .= ", user_pass = :user_pass";
            $params[':user_pass'] = $password; // Direct de string uit <password> opslaan
            error_log("Received password for user update ID $userId: $password");
        }
    
        $query .= " WHERE ID = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
    
        // Metadata
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
    
        error_log("Updated user with ID: $userId, set user_login and user_email to '$email'");
    }

    private function deleteUser(int $userId) {
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_id' => $userId]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Gebruiker $userId niet gevonden");
        }

        $query = "DELETE FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);

        $metaQuery = "DELETE FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id";
        $metaStmt = $this->db->prepare($metaQuery);
        $metaStmt->execute([':user_id' => $userId]);

        $umQuery = "DELETE FROM {$this->table_prefix}_um_metadata WHERE user_id = :user_id";
        $umStmt = $this->db->prepare($umQuery);
        $umStmt->execute([':user_id' => $userId]);

        error_log("Deleted user with ID: $userId");
    }

    private function sanitizeField($value) {
        return htmlspecialchars(strip_tags((string)$value));
    }

    
}

// Start de consumer
try {
    new RabbitMQ_Consumer();
} catch (Exception $e) {
    error_log("Consumer failed to start: " . $e->getMessage());
    exit(1);
}