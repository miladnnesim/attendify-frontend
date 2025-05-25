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

class UnifiedConsumerEventSession {
    private $connection;
    private $channel;
    private $db;
    private $queue = 'frontend.event';

    public function __construct(?PDO $db = null, ?AMQPChannel $channel = null) {
        $this->db = $db ?? $this->connectToDB();
        $this->initTables();
        $this->channel = $channel ?? $this->connectToRabbitMQ();
        $this->processMessages();
    }

    private function connectToDB(): PDO {
        try {
            $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
            $pdo = new PDO($dsn, getenv('LOCAL_DB_USER') ?: 'root', getenv('LOCAL_DB_PASSWORD') ?: 'root');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->sendMonitoringLog("✅ Connected to database", "info");
            error_log("✅ Connected to database");
            return $pdo;
        } catch (PDOException $e) {
            $this->sendMonitoringLog("❌ Database connection failed: " . $e->getMessage(), "error");
            error_log("❌ Database connection failed: " . $e->getMessage());
            exit(1);
        }
    }

    private function initTables() {
        $this->initTable('user_event', [
            'user_id' => 'VARCHAR(255)',
            'event_id' => 'VARCHAR(255)'
        ], ['user_id', 'event_id']);

        $this->initTable('user_session', [
            'user_id' => 'VARCHAR(255)',
            'session_id' => 'VARCHAR(255)'
        ], ['user_id', 'session_id']);

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS wp_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid VARCHAR(255) UNIQUE,
                title TEXT,
                description TEXT,
                location TEXT,
                start_date DATE,
                end_date DATE,
                start_time TIME,
                end_time TIME,
                organizer_name VARCHAR(255),
                organizer_uid VARCHAR(255),
                entrance_fee DECIMAL(10,2),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS wp_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid VARCHAR(255) UNIQUE,
                event_uid VARCHAR(255),
                title TEXT,
                description TEXT,
                date DATE,
                start_time TIME,
                end_time TIME,
                location TEXT,
                max_attendees INT,
                speaker_name VARCHAR(255),
                speaker_bio TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $this->sendMonitoringLog("✅ All tables initialized", "info");
        error_log("✅ All tables initialized");
    }

    private function initTable($table, $fields, $primaryKeys) {
        $columns = array_map(fn($col, $type) => "`$col` $type NOT NULL", array_keys($fields), $fields);
        $pk = implode(', ', array_map(fn($col) => "`$col`", $primaryKeys));
        $query = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(', ', $columns) . ", PRIMARY KEY ($pk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->db->exec($query);

        $stmt = $this->db->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        foreach ($fields as $col => $type) {
            if (!in_array($col, $existing)) {
                $this->db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $type NOT NULL");
                $this->sendMonitoringLog("➕ Column '$col' added to '$table'", "info");
                error_log("➕ Column '$col' added to '$table'");
            }
        }
    }

    private function connectToRabbitMQ(): AMQPChannel {
        $maxRetries = 5;
        $retryDelay = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            try {
                $this->connection = new AMQPStreamConnection(
                    'rabbitmq',
                    getenv('RABBITMQ_AMQP_PORT') ?: 5672,
                    getenv('RABBITMQ_HOST'),
                    getenv('RABBITMQ_PASSWORD'),
                    getenv('RABBITMQ_USER')
                );
                $channel = $this->connection->channel();
                $channel->basic_qos(null, 1, null);
                $this->sendMonitoringLog("✅ Connected to RabbitMQ", "info");
                error_log("✅ Connected to RabbitMQ");
                return $channel;
            } catch (AMQPIOException $e) {
                $this->sendMonitoringLog("❌ RabbitMQ connection failed: " . $e->getMessage(), "error");
                error_log("❌ RabbitMQ connection failed: " . $e->getMessage());
                if ($i === $maxRetries - 1) {
                    throw $e;
                }
                sleep($retryDelay);
            }
        }
    }

    private function processMessages() {
        $callback = function (AMQPMessage $msg) {
            try {
                $cleanedBody = $this->sanitizeXmlBody($msg->body);
                $xml = simplexml_load_string($cleanedBody);
                if (!$xml) throw new Exception("Invalid XML format");

                $operation = (string)$xml->info->operation;

                if (isset($xml->event_attendee)) {
                    $this->processRegistration('event', $operation, trim((string)$xml->event_attendee->uid), trim((string)$xml->event_attendee->event_id));
                    $this->sendMonitoringLog("User " . $xml->event_attendee->uid . " event $operation: " . $xml->event_attendee->event_id, "info");
                    error_log("User " . $xml->event_attendee->uid . " event $operation: " . $xml->event_attendee->event_id);
                } elseif (isset($xml->session_attendee)) {
                    $this->processRegistration('session', $operation, trim((string)$xml->session_attendee->uid), trim((string)$xml->session_attendee->session_id));
                    $this->sendMonitoringLog("User " . $xml->session_attendee->uid . " session $operation: " . $xml->session_attendee->session_id, "info");
                    error_log("User " . $xml->session_attendee->uid . " session $operation: " . $xml->session_attendee->session_id);
                } elseif (isset($xml->event)) {
                    $this->handleEvent($xml->event, $operation);
                } elseif (isset($xml->session)) {
                    $this->handleSession($xml->session, $operation);
                } else {
                    throw new Exception("Unknown message type");
                }

                $msg->ack();
            } catch (Exception $e) {
                $this->sendMonitoringLog("[ERROR] " . $e->getMessage(), "error");
                error_log("[ERROR] " . $e->getMessage());
                $msg->ack();
            }
        };

        $queues = ['frontend.event', 'frontend.session'];
        foreach ($queues as $queue) {
            $this->channel->basic_consume($queue, '', false, false, false, false, $callback);
        }

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    private function processRegistration($type, $operation, $user_id, $entity_id) {
        $table = $type === 'event' ? 'user_event' : 'user_session';
        $column = $type === 'event' ? 'event_id' : 'session_id';

        if ($operation === 'register') {
            $stmt = $this->db->prepare("INSERT IGNORE INTO `$table` (user_id, `$column`) VALUES (:uid, :eid)");
            $stmt->execute([':uid' => $user_id, ':eid' => $entity_id]);
        } elseif ($operation === 'unregister') {
            $stmt = $this->db->prepare("DELETE FROM `$table` WHERE user_id = :uid AND `$column` = :eid");
            $stmt->execute([':uid' => $user_id, ':eid' => $entity_id]);
        } else {
            throw new Exception("Unknown operation: $operation");
        }
    }

    private function handleEvent(SimpleXMLElement $event, $operation) {
        $uid = $this->sanitizeField($event->uid);
        if (!$uid) throw new Exception("Event uid missing");

        switch ($operation) {
            case 'create':
            case 'update':
                $query = "
                    INSERT INTO wp_events (uid, title, description, location, start_date, end_date, start_time, end_time, organizer_name, organizer_uid, entrance_fee)
                    VALUES (:uid, :title, :desc, :loc, :sd, :ed, :st, :et, :oname, :ouid, :fee)
                    ON DUPLICATE KEY UPDATE
                        title = VALUES(title),
                        description = VALUES(description),
                        location = VALUES(location),
                        start_date = VALUES(start_date),
                        end_date = VALUES(end_date),
                        start_time = VALUES(start_time),
                        end_time = VALUES(end_time),
                        organizer_name = VALUES(organizer_name),
                        organizer_uid = VALUES(organizer_uid),
                        entrance_fee = VALUES(entrance_fee)
                ";
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    ':uid' => $uid,
                    ':title' => $this->sanitizeField($event->title),
                    ':desc' => $this->sanitizeField($event->description),
                    ':loc' => $this->sanitizeField($event->location),
                    ':sd' => $event->start_date,
                    ':ed' => $event->end_date,
                    ':st' => $event->start_time,
                    ':et' => $event->end_time,
                    ':oname' => $this->sanitizeField($event->organizer_name),
                    ':ouid' => $this->sanitizeField($event->organizer_uid),
                    ':fee' => $event->entrance_fee
                ]);
                $this->sendMonitoringLog("Event $uid " . ($operation === 'create' ? 'created' : 'updated'), "info");
                error_log("✅ Event $uid " . ($operation === 'create' ? 'created' : 'updated'));
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM wp_events WHERE uid = :uid");
                $stmt->execute([':uid' => $uid]);
                $this->sendMonitoringLog("Event $uid deleted", "info");
                error_log("❌ Event $uid deleted");
                break;
            default:
                throw new Exception("Unknown operation for event: $operation");
        }
    }

    private function handleSession(SimpleXMLElement $session, $operation) {
        $uid = $this->sanitizeField($session->uid);
        if (!$uid) throw new Exception("Session uid missing");

        switch ($operation) {
            case 'create':
            case 'update':
                $query = "
                    INSERT INTO wp_sessions (uid, event_uid, title, description, date, start_time, end_time, location, max_attendees, speaker_name, speaker_bio)
                    VALUES (:uid, :euid, :title, :desc, :date, :st, :et, :loc, :max, :sname, :sbio)
                    ON DUPLICATE KEY UPDATE
                        event_uid = VALUES(event_uid),
                        title = VALUES(title),
                        description = VALUES(description),
                        date = VALUES(date),
                        start_time = VALUES(start_time),
                        end_time = VALUES(end_time),
                        location = VALUES(location),
                        max_attendees = VALUES(max_attendees),
                        speaker_name = VALUES(speaker_name),
                        speaker_bio = VALUES(speaker_bio)
                ";
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    ':uid' => $uid,
                    ':euid' => $this->sanitizeField($session->event_id),
                    ':title' => $this->sanitizeField($session->title),
                    ':desc' => $this->sanitizeField($session->description),
                    ':date' => $session->date,
                    ':st' => $session->start_time,
                    ':et' => $session->end_time,
                    ':loc' => $this->sanitizeField($session->location),
                    ':max' => intval($session->max_attendees),
                    ':sname' => $this->sanitizeField($session->speaker->name),
                    ':sbio' => $this->sanitizeField($session->speaker->bio)
                ]);
                $this->sendMonitoringLog("Session $uid " . ($operation === 'create' ? 'created' : 'updated'), "info");
                error_log("✅ Session $uid " . ($operation === 'create' ? 'created' : 'updated'));
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM wp_sessions WHERE uid = :uid");
                $stmt->execute([':uid' => $uid]);
                $this->sendMonitoringLog("Session $uid deleted", "info");
                error_log("❌ Session $uid deleted");
                break;
            default:
                throw new Exception("Unknown operation for session: $operation");
        }
    }

    private function sanitizeField($value) {
        return htmlspecialchars(strip_tags((string)$value), ENT_XML1);
    }

    private function sanitizeXmlBody($xml) {
        return preg_replace_callback('/>([^<]+)</', function ($matches) {
            return '>' . htmlspecialchars($matches[1], ENT_XML1) . '<';
        }, $xml);
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
        $sender = "frontend-event-consumer";
        $timestamp = date('c');
        $logXml = "<log>"
            . "<sender>" . htmlspecialchars($sender) . "</sender>"
            . "<timestamp>" . htmlspecialchars($timestamp) . "</timestamp>"
            . "<level>" . htmlspecialchars($level) . "</level>"
            . "<message>" . htmlspecialchars($message) . "</message>"
            . "</log>";
        $amqpMsg = new AMQPMessage($logXml);
        $this->channel->basic_publish($amqpMsg, 'event', 'monitoring.log');
    }
}

// onderaan UnifiedConsumerEventSession.php
if (php_sapi_name() === 'cli' && realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
    try {
        new UnifiedConsumerEventSession();
    } catch (Exception $e) {
        error_log("❌ Consumer failed to start: " . $e->getMessage());
        // Monitoring log bij opstartfout
        if (isset($consumer) && method_exists($consumer, 'sendMonitoringLog')) {
            $consumer->sendMonitoringLog("❌ Consumer failed to start: " . $e->getMessage(), "error");
        }
        exit(1);
    }
}
