<?php
namespace App;
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Channel\AMQPChannel;
use PDO;
use PDOStatement;
use Exception;
use DateTime;
use SimpleXMLElement;

class RabbitMQ_Consumer {
    private $connection;
    private $channel;
    private $db;
    private $exchange = 'user-management';
    private $queue = 'frontend.user';
    private $table_prefix = 'wp';

    public function __construct(?PDO $db = null, ?AMQPChannel $channel = null) {
        $this->db = $db ?? $this->createDbConnection();
        $this->channel = $channel ?? $this->connectRabbitMQ();
    }

    public function run(): void {
        $this->setupQueue();
        $this->processMessages();
    }

    private function createDbConnection(): PDO {
        $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
        $pdo = new PDO($dsn, getenv('LOCAL_DB_USER'), getenv('LOCAL_DB_PASSWORD'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->sendMonitoringLog("Successfully connected to database", "info");
        error_log("✅ Successfully connected to database");
        return $pdo;
    }

    private function connectRabbitMQ(): AMQPChannel {
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
                $channel = $this->connection->channel();
                $this->sendMonitoringLog("Successfully connected to RabbitMQ", "info");
                error_log("✅ Successfully connected to RabbitMQ");
                return $channel;
            } catch (AMQPIOException $e) {
                $this->sendMonitoringLog("RabbitMQ connection failed: " . $e->getMessage(), "error");
                error_log("RabbitMQ connection failed: " . $e->getMessage());
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                error_log("Retrying RabbitMQ connection ($i/$maxRetries)...");
                sleep($retryDelay);
            }
        }
        throw new Exception("Failed to connect to RabbitMQ after retries");
    }

    private function setupQueue() {
        // Optionally declare queue or exchange here if needed
        $this->sendMonitoringLog("Listening to queue: {$this->queue}", "info");
        error_log("🎧 Listening to queue: {$this->queue}");
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
                    $this->sendMonitoringLog("Bericht van frontend genegeerd", "info");
                    error_log("ℹ️ Sender is frontend, message ignored.");
                    $msg->ack();
                    return;
                }

                // Pass $sender to handleMessage
                $this->handleMessage($msg, $sender);
                $this->sendMonitoringLog("Bericht succesvol verwerkt", "info");
                error_log("✅ Bericht succesvol verwerkt");
                $msg->ack();
            } catch (Exception $e) {
                $this->sendMonitoringLog("[ERROR] " . $e->getMessage(), "error");
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

    public function handleMessage(AMQPMessage $msg, $sender) {
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
        $routing_key = 'user.passwordGenerated';
        $message = new AMQPMessage($messageData, ['content_type' => 'text/xml']);
        $this->channel->basic_publish($message, $exchange, $routing_key);
        $this->sendMonitoringLog("Sent passwordGenerated mail for user", "info");
        error_log("Sent passwordGenerated mail for user");
    }

    private function createUser(SimpleXMLElement $userNode, $sender) {
        $email = $this->sanitizeField($userNode->email);
        $display_name = $this->sanitizeField($userNode->first_name . ' ' . $userNode->last_name);
        $uid = $this->sanitizeField($userNode->uid);

        if (empty($uid)) {
            throw new Exception("UID is verplicht bij create operatie");
        }

        $this->sendMonitoringLog("Received UID from message: $uid", "info");
        error_log("Received UID from message: $uid");

        // Check if user already exists by UID
        $checkUidQuery = "SELECT user_id FROM {$this->table_prefix}_usermeta WHERE meta_key = 'uid' AND meta_value = :uid LIMIT 1";
        $checkUidStmt = $this->db->prepare($checkUidQuery);
        $checkUidStmt->execute([':uid' => $uid]);
        $existingUserByUid = $checkUidStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingUserByUid) {
            $this->sendMonitoringLog("User with UID '$uid' already exists with ID: " . $existingUserByUid['user_id'] . ". Skipping creation.", "info");
            error_log("User with UID '$uid' already exists with ID: " . $existingUserByUid['user_id'] . ". Skipping creation.");
            return;
        }

        $password = (string)$userNode->password;
        if (empty($password)) {
            throw new Exception("No password provided in user.register message from CRM/Odoo");
        }

        $hashed_activation_key = null;

        if ($sender == 'CRM' || $sender == 'Odoo'|| $sender == 'crm'|| $sender == 'odoo') {
            $wp_host = rtrim(getenv('WORDPRESS_HOST'), '/');
            $api_url = "http://wordpress:80/?rest_route=/myapiv2/set-activation-key";
            $this->sendMonitoringLog("API URL: " . $api_url, "info");
            error_log("API URL: " . $api_url);

            $shared_secret = getenv('MY_API_SHARED_SECRET');
            if (!$shared_secret) {
                $this->sendMonitoringLog('Shared secret not set in environment variables!', "error");
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
                $this->sendMonitoringLog('cURL error: ' . curl_error($ch), "error");
                error_log('cURL error: ' . curl_error($ch));
                throw new Exception('cURL error: ' . curl_error($ch));
            }
            curl_close($ch);

            $response_data = json_decode($response, true);
            if (isset($response_data['hashed_activation_key'])) {
                $hashed_activation_key = $response_data['hashed_activation_key'];

                $activationLink = htmlspecialchars($wp_host . "/account-activeren/?key=" . $activation_key . "&login=" . rawurlencode($email));
                $emailEscaped = htmlspecialchars($email);

                $xmlMessage = <<<XML
                <mail>
                <user>{$emailEscaped}</user>
                <activationLink>{$activationLink}</activationLink>
                </mail>
                XML;

                $this->sendToMailingQueue($xmlMessage);

                $this->sendMonitoringLog('Hashed Activation Key created for user', "info");
                error_log('Hashed Activation Key created for user');
            } else {
                $this->sendMonitoringLog('No hashed activation key found in response', "error");
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
        $this->sendMonitoringLog("Inserted user with auto-increment ID: $user_id", "info");
        error_log("Inserted user with auto-increment ID: $user_id");
        $isAdmin = strtolower((string)($userNode->is_admin ?? 'false')) === 'true';

        // Insert user meta fields
        $metaFields = [
            'uid' => $uid,
            'nickname' => $email,
            'first_name' => $this->sanitizeField($userNode->first_name),
            'last_name' => $this->sanitizeField($userNode->last_name),
            'birth_date' => $this->sanitizeField($userNode->date_of_birth ?? ''),
            'phone_number' => $this->sanitizeField($userNode->phone_number ?? ''),
            'user_title' => $this->sanitizeField($userNode->title ?? ''),
            'street_name' => $this->sanitizeField($userNode->address->street ?? ''),
            'bus_nr' => $this->sanitizeField($userNode->address->bus_number ?? ''),
            'city' => $this->sanitizeField($userNode->address->city ?? ''),
            'province' => $this->sanitizeField($userNode->address->province ?? ''),
            'user_country' => $this->sanitizeField($userNode->address->country ?? ''),
            'company_vat_number' => $this->sanitizeField($userNode->company->VAT_number ?? ''),
            'account_status' => 'approved',
            'wp_capabilities' => $isAdmin ? 'a:1:{s:13:"administrator";b:1;}' : 'a:1:{s:10:"subscriber";b:1;}',
            'wp_user_level'   => $isAdmin ? '10' : '0',
            'is_admin'        => $isAdmin ? '1' : '0'
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

        $this->sendMonitoringLog("Created user with ID: $user_id, stored UID $uid, role: " . ($isAdmin ? 'admin' : 'subscriber'), "info");
        error_log("Created user with ID: $user_id, stored UID $uid, role: " . ($isAdmin ? 'admin' : 'subscriber'));
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
            $this->sendMonitoringLog("Gebruiker met UID $uid niet gevonden voor update", "error");
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
            $this->sendMonitoringLog("Received password for user update UID $uid: $password", "info");
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
            'vat_number' => $this->sanitizeField($userNode->company->VAT_number ?? ''),
            'full_name' => trim($this->sanitizeField($userNode->first_name) . ' ' . $this->sanitizeField($userNode->last_name))
        ];

        foreach ($updateFields as $key => $value) {
            if ($value === '' || $value === null) continue;

            $checkMeta = $this->db->prepare("SELECT umeta_id FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id AND meta_key = :meta_key");
            $checkMeta->execute([':user_id' => $userId, ':meta_key' => $key]);
            $exists = $checkMeta->fetchColumn();

            if ($exists) {
                $updateMeta = $this->db->prepare("UPDATE {$this->table_prefix}_usermeta SET meta_value = :meta_value WHERE user_id = :user_id AND meta_key = :meta_key");
                $updateMeta->execute([':meta_value' => $value, ':user_id' => $userId, ':meta_key' => $key]);
            } else {
                $insertMeta = $this->db->prepare("INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value) VALUES (:user_id, :meta_key, :meta_value)");
                $insertMeta->execute([':user_id' => $userId, ':meta_key' => $key, ':meta_value' => $value]);
            }
        }

        $this->sendMonitoringLog("Updated user with UID: $uid (ID: $userId)", "info");
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
            $this->sendMonitoringLog("Gebruiker met UID $uid niet gevonden voor delete", "error");
            throw new Exception("Gebruiker met UID $uid niet gevonden");
        }

        $userId = $user['ID'];

        $deleteMeta = "DELETE FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id";
        $stmtMeta = $this->db->prepare($deleteMeta);
        $stmtMeta->execute([':user_id' => $userId]);

        $deleteUser = "DELETE FROM {$this->table_prefix}_users WHERE ID = :user_id";
        $stmtUser = $this->db->prepare($deleteUser);
        $stmtUser->execute([':user_id' => $userId]);

        $this->sendMonitoringLog("Deleted user with UID: $uid (ID: $userId)", "info");
        error_log("Deleted user with UID: $uid (ID: $userId)");
    }

    private function sanitizeField($value) {
        return htmlspecialchars(strip_tags((string)$value));
    }

    private function sendMonitoringLog(string $message, string $level = "info") {
        if (!$this->channel) {
            error_log("[monitoring.log skipped]: $message");
            return;
        }
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        // Tijdens unit tests: skip publish naar monitoring
        return;
    }
        $sender = "frontend-user-consumer";
        $timestamp = date('c');
        $logXml = "<log>"
            . "<sender>" . htmlspecialchars($sender) . "</sender>"
            . "<timestamp>" . htmlspecialchars($timestamp) . "</timestamp>"
            . "<level>" . htmlspecialchars($level) . "</level>"
            . "<message>" . htmlspecialchars($message) . "</message>"
            . "</log>";
        $amqpMsg = new AMQPMessage($logXml);
        $this->channel->basic_publish($amqpMsg, 'user-management', 'monitoring.log');
    }
}

if (__FILE__ === realpath($_SERVER['SCRIPT_FILENAME'])) {
    try {
        $consumer = new RabbitMQ_Consumer();
        $consumer->run();
    } catch (Exception $e) {
        error_log("Consumer failed to start: " . $e->getMessage());
        if (method_exists($consumer ?? null, 'sendMonitoringLog')) {
            $consumer->sendMonitoringLog("Consumer failed to start: " . $e->getMessage(), "error");
        }
        exit(1);
    }
}
