<?php
require_once  '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;

class RabbitMQ_Consumer {
    private $connection;
    private $channel;
    private $db;
    private $exchange = 'user-management';
    private $queue = 'frontend.user';
    private $table_prefix = 'wp'; // Pas aan als je een andere prefix gebruikt

    public function __construct() {
        // Databaseverbinding met PDO
        $dsn = "mysql:host=" . "db" . ";dbname=" . "wordpress" . ";charset=utf8mb4";
        try {
            $this->db = new PDO($dsn, "root", "root");
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("Successfully connected to database");
        } catch (PDOException $e) {
            error_log("Failed to connect to database: " . $e->getMessage());
            exit(1);
        }

        // Start RabbitMQ-verwerking
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
                    'rabbitmq',
                    5672,
                    'attendify',
                    'uXe5u1oWkh32JyLA',
                    'attendify'
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

                // Controleer de afzender
                $sender = (string)$xml->info->sender;
                if (strtolower($sender) === 'frontend') {
                    $msg->ack();
                    return;
                }

                $this->handleMessage($msg);
                $msg->ack();
            } catch (Exception $e) {
                error_log("[ERROR] " . $e->getMessage());
                $msg->nack(false, true); // Requeue message
            }
        };

        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume(
            $this->queue,
            '',
            false,
            false,
            false,
            false,
            $callback
        );

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
            case 'register':
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
        $userIdFromMessage = (int)$userNode->id; // Optionele ID uit het bericht
    
        // Log de ontvangen ID voor debugging
        error_log("Received user ID from message: $userIdFromMessage");
    
        // Controleer of een gebruiker met hetzelfde e-mailadres al bestaat
        $checkEmailQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_email = :user_email LIMIT 1";
        $checkEmailStmt = $this->db->prepare($checkEmailQuery);
        $checkEmailStmt->execute([':user_email' => $email]);
        $existingUserByEmail = $checkEmailStmt->fetch(PDO::FETCH_ASSOC);
    
        if ($existingUserByEmail) {
            error_log("User with email '$email' already exists with ID: " . $existingUserByEmail['ID'] . ". Skipping creation.");
            return; // Sla de creatie over
        }
    
        // Controleer of een gebruiker met dezelfde ID al bestaat (indien meegegeven)
        if ($userIdFromMessage > 0) {
            $checkIdQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE ID = :user_id LIMIT 1";
            $checkIdStmt = $this->db->prepare($checkIdQuery);
            $checkIdStmt->execute([':user_id' => $userIdFromMessage]);
            $existingUserById = $checkIdStmt->fetch(PDO::FETCH_ASSOC);
    
            if ($existingUserById) {
                error_log("User with ID '$userIdFromMessage' already exists. Skipping creation.");
                return; // Sla de creatie over
            }
        }
    
        // Als geen duplicaten zijn gevonden, ga verder met het aanmaken van de gebruiker
        $password = password_hash($this->generatePassword(), PASSWORD_DEFAULT);
    
        // Gebruik de opgegeven ID als die aanwezig is, anders laat de database een ID genereren
        if ($userIdFromMessage > 0) {
            $query = "INSERT INTO {$this->table_prefix}_users 
                      (ID, user_login, user_pass, user_email, user_registered, display_name)
                      VALUES (:user_id, :user_login, :user_pass, :user_email, NOW(), :display_name)";
            $stmt = $this->db->prepare($query);
            $stmt->execute([
                ':user_id' => $userIdFromMessage,
                ':user_login' => $email,
                ':user_pass' => $password,
                ':user_email' => $email,
                ':display_name' => $display_name
            ]);
            $user_id = $userIdFromMessage; // Gebruik de opgegeven ID
            error_log("Inserted user with specified ID: $user_id");
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
            $user_id = $this->db->lastInsertId(); // Gebruik de gegenereerde ID
            error_log("Inserted user with auto-increment ID: $user_id");
        }
    
        // Metadata voor wp_usermeta
        $metaFields = [
            'first_name' => $userNode->first_name,
            'last_name' => $userNode->last_name,
            'birth_date' => $userNode->date_of_birth,
            'phone_number' => $userNode->phone_number,
            'account_status' => 'approved', // Direct goedgekeurd
            'wp_capabilities' => 'a:1:{s:10:"subscriber";b:1;}' // Exacte serialized string voor subscriber
        ];
    
        // Ultimate Member velden
        $umFields = [
            'user_title' => $userNode->title,
            'street_name' => $userNode->address->street,
            'bus_nr' => $userNode->address->bus_number,
            'city' => $userNode->address->city,
            'province' => $userNode->address->province,
            'user_country' => $userNode->address->country,
            'vat_number' => $userNode->company->VAT_number
        ];
    
        $allFields = array_merge($metaFields, $umFields);
        $metaQuery = "INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value) 
                      VALUES (:user_id, :meta_key, :meta_value)";
        $metaStmt = $this->db->prepare($metaQuery);
    
        foreach ($allFields as $key => $value) {
            error_log("Adding meta for user $user_id: $key = $value"); // Debug logging
            $metaStmt->execute([
                ':user_id' => $user_id,
                ':meta_key' => $key,
                ':meta_value' => $this->sanitizeField($value)
            ]);
        }
    
        // Ultimate Member specifieke tabel (optioneel)
        $umQuery = "INSERT INTO {$this->table_prefix}_um_metadata (user_id, um_key, um_value) 
                    VALUES (:user_id, :um_key, :um_value)";
        $umStmt = $this->db->prepare($umQuery);
        foreach ($umFields as $key => $value) {
            $umStmt->execute([
                ':user_id' => $user_id,
                ':um_key' => $key,
                ':meta_value' => $this->sanitizeField($value)
            ]);
        }
    
        error_log("Created user with ID: $user_id, set role to 'subscriber' and account_status to approved");
    }

    private function updateUser(int $userId, SimpleXMLElement $userNode) {
        // Controleer of de gebruiker bestaat
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_id' => $userId]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Gebruiker $userId niet gevonden");
        }
    
        // Update wp_users met zowel user_email als user_login
        $email = $this->sanitizeField($userNode->email);
        $query = "UPDATE {$this->table_prefix}_users 
                  SET user_email = :user_email, user_login = :user_login 
                  WHERE ID = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_email' => $email,
            ':user_login' => $email, // user_login wordt gelijk aan user_email
            ':user_id' => $userId
        ]);
    
        // Update metadata
        $updateFields = [
            'first_name' => $userNode->first_name,
            'last_name' => $userNode->last_name,
            'phone_number' => $userNode->phone_number,
            'birth_date' => $userNode->date_of_birth,
            'user_title' => $userNode->title,
            'street_name' => $userNode->address->street,
            'bus_nr' => $userNode->address->bus_number,
            'city' => $userNode->address->city,
            'province' => $userNode->address->province,
            'user_country' => $userNode->address->country,
            'vat_number' => $userNode->company->VAT_number
        ];
    
        $metaQuery = "INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value)
                      VALUES (:user_id, :meta_key, :meta_value)
                      ON DUPLICATE KEY UPDATE meta_value = :meta_value";
        $metaStmt = $this->db->prepare($metaQuery);
    
        foreach ($updateFields as $key => $value) {
            $metaStmt->execute([
                ':user_id' => $userId,
                ':meta_key' => $key,
                ':meta_value' => $this->sanitizeField($value)
            ]);
        }
    
        // Update UM metadata
        $umQuery = "INSERT INTO {$this->table_prefix}_um_metadata (user_id, um_key, um_value)
                    VALUES (:user_id, :um_key, :um_value)
                    ON DUPLICATE KEY UPDATE um_value = :um_value";
        $umStmt = $this->db->prepare($umQuery);
        foreach ($updateFields as $key => $value) {
            $umStmt->execute([
                ':user_id' => $userId,
                ':um_key' => $key,
                ':um_value' => $this->sanitizeField($value)
            ]);
        }
    
        error_log("Updated user with ID: $userId, set user_login and user_email to '$email'");
    }

    private function deleteUser(int $userId) {
        // Controleer of de gebruiker bestaat
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_id' => $userId]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Gebruiker $userId niet gevonden");
        }

        // Verwijder uit wp_users
        $query = "DELETE FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_id' => $userId]);

        // Verwijder metadata
        $metaQuery = "DELETE FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id";
        $metaStmt = $this->db->prepare($metaQuery);
        $metaStmt->execute([':user_id' => $userId]);

        // Verwijder UM metadata
        $umQuery = "DELETE FROM {$this->table_prefix}_um_metadata WHERE user_id = :user_id";
        $umStmt = $this->db->prepare($umQuery);
        $umStmt->execute([':user_id' => $userId]);

        error_log("Deleted user with ID: $userId");
    }

    private function sanitizeField($value) {
        return htmlspecialchars(strip_tags((string)$value));
    }

    private function generatePassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
        return substr(str_shuffle($chars), 0, $length);
    }
}

// Start de consumer
try {
    new RabbitMQ_Consumer();
} catch (Exception $e) {
    error_log("Consumer failed to start: " . $e->getMessage());
    exit(1);
}