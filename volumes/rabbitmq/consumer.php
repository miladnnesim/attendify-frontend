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
                    'rabbitmq',
                    getenv('RABBITMQ_AMQP_PORT'),
                    getenv('RABBITMQ_HOST'),
                    getenv('RABBITMQ_PASSWORD'),
                    getenv('RABBITMQ_USER')
                );
                $this->channel = $this->connection->channel();
                error_log("Successfully connected to RabbitMQ");
                return;
            } catch (AMQPIOException $e) {
                if ($i === $maxRetries - 1) {
                    error_log("Could not connect to RabbitMQ: " . $e->getMessage());
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
                    throw new Exception("Invalid XML format");
                }

                $sender = (string)$xml->info->sender;
                if (strtolower($sender) === 'frontend') {
                    $msg->ack();
                    return;
                }

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
            throw new Exception("Invalid XML format");
        }

        $operation = (string)$xml->info->operation;
        $userNode = $xml->user;
        $email = $this->sanitizeField($userNode->email);

        // Generate unique user ID based on the email or use a specific strategy
        $userIdFromMessage = $this->generateUserId($email);

        switch ($operation) {
            case 'create':
                $this->createUser($userNode, $sender, $userIdFromMessage);
                break;
            case 'update':
                $this->updateUser($userIdFromMessage, $userNode);
                break;
            case 'delete':
                $this->deleteUser($userIdFromMessage);
                break;
            default:
                throw new Exception("Unknown operation: $operation");
        }
    }

    private function generateUserId($email) {
        // Generate a unique user ID based on the email (or use another strategy)
        return md5(strtolower(trim($email)));
    }

    private function createUser(SimpleXMLElement $userNode, $sender, $generatedUserId) {
        $email = $this->sanitizeField($userNode->email);
        $display_name = $this->sanitizeField($userNode->first_name . ' ' . $userNode->last_name);

        // Check if user already exists by generated user ID
        $checkUserIdQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_login = :user_login LIMIT 1";
        $checkUserIdStmt = $this->db->prepare($checkUserIdQuery);
        $checkUserIdStmt->execute([':user_login' => $generatedUserId]);
        $existingUserById = $checkUserIdStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUserById) {
            error_log("User with generated ID '$generatedUserId' already exists. Skipping creation.");
            return;
        }

        // Password from CRM/Odoo
        $password = (string)$userNode->password;
        if (empty($password)) {
            throw new Exception("No password provided in user.register message from CRM/Odoo");
        }

        // Insert new user into database
        $query = "INSERT INTO {$this->table_prefix}_users
            (user_login, user_pass, user_email, user_registered, display_name)
            VALUES (:user_login, :user_pass, :user_email, NOW(), :display_name)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':user_login' => $generatedUserId,
            ':user_pass' => $password,
            ':user_email' => $email,
            ':display_name' => $display_name
        ]);

        $user_id = $this->db->lastInsertId();
        error_log("Inserted user with generated ID: $generatedUserId");

        // Insert user meta fields
        $metaFields = [
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

        error_log("Created user with generated ID: $generatedUserId, set role to 'subscriber' and account_status to approved");
    }

    private function updateUser($generatedUserId, SimpleXMLElement $userNode) {
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_login = :user_login";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_login' => $generatedUserId]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception("User with generated ID $generatedUserId not found");
        }

        $userId = $user['ID'];

        $query = "UPDATE {$this->table_prefix}_users
                  SET user_email = :user_email, user_login = :user_login";
        $params = [
            ':user_email' => $userNode->email,
            ':user_login' => $generatedUserId
        ];

        if (isset($userNode->password) && !empty($userNode->password)) {
            $password = (string)$userNode->password;
            $query .= ", user_pass = :user_pass";
            $params[':user_pass'] = $password;
        }

        $query .= " WHERE user_login = :user_login";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        error_log("Updated user with generated ID: $generatedUserId");
    }

    private function deleteUser($generatedUserId) {
        $checkQuery = "SELECT ID FROM {$this->table_prefix}_users WHERE user_login = :user_login";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute([':user_login' => $generatedUserId]);
        $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new Exception("User with generated ID $generatedUserId not found");
        }

        $userId = $user['ID'];

        $query = "DELETE FROM {$this->table_prefix}_users WHERE user_login = :user_login";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':user_login' => $generatedUserId]);

        $metaQuery = "DELETE FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id";
        $metaStmt = $this->db->prepare($metaQuery);
        $metaStmt->execute([':user_id' => $userId]);

        error_log("Deleted user with generated ID: $generatedUserId");
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
