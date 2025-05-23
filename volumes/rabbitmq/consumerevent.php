<?php
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPIOException;

class UnifiedConsumer {
    private $connection;
    private $channel;
    private $db;
    private $queue = 'frontend.event';

    public function __construct() {
        $this->connectToDB();
        $this->initTables();
        $this->connectToRabbitMQ();
        $this->processMessages();
    }

    private function connectToDB() {
        try {
            $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
            $this->db = new PDO($dsn, getenv('LOCAL_DB_USER') ?: 'root', getenv('LOCAL_DB_PASSWORD') ?: 'root');
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("✅ Connected to database");
        } catch (PDOException $e) {
            error_log("❌ Database connection failed: " . $e->getMessage());
            exit(1);
        }
    }

    private function initTables() {
        // Table: user_event
        $this->initTable('user_event', [
            'user_id' => 'VARCHAR(255)',
            'event_id' => 'VARCHAR(255)'
        ], ['user_id', 'event_id']);

        // Table: user_session
        $this->initTable('user_session', [
            'user_id' => 'VARCHAR(255)',
            'session_id' => 'VARCHAR(255)'
        ], ['user_id', 'session_id']);

        // Table: wp_events
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

        // Table: wp_sessions
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
                error_log("➕ Column '$col' added to '$table'");
            }
        }
    }

    private function connectToRabbitMQ() {
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
                $this->channel = $this->connection->channel();
                $this->channel->basic_qos(null, 1, null);
                error_log("✅ Connected to RabbitMQ");
                return;
            } catch (AMQPIOException $e) {
                if ($i === $maxRetries - 1) {
                    error_log("❌ RabbitMQ connection failed: " . $e->getMessage());
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
                    $user_id = trim((string)$xml->event_attendee->uid);
                    $event_id = trim((string)$xml->event_attendee->event_id);
                    if (!$user_id || !$event_id) throw new Exception("user_id or event_id missing");
                    $this->processRegistration('event', $operation, $user_id, $event_id);
                } elseif (isset($xml->session_attendee)) {
                    $user_id = trim((string)$xml->session_attendee->uid);
                    $session_id = trim((string)$xml->session_attendee->session_id);
                    if (!$user_id || !$session_id) throw new Exception("user_id or session_id missing");
                    $this->processRegistration('session', $operation, $user_id, $session_id);
                } elseif (isset($xml->event)) {
                    $this->handleEvent($xml->event, $operation);
                } elseif (isset($xml->session)) {
                    $this->handleSession($xml->session, $operation);
                } else {
                    throw new Exception("Unknown message type");
                }

                $msg->ack();
            } catch (Exception $e) {
                error_log("[ERROR] " . $e->getMessage());
                $msg->ack(); // Acknowledge to avoid requeueing, as per RegistrationConsumer behavior
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
            error_log("✅ User $user_id registered for $type $entity_id");
        } elseif ($operation === 'unregister') {
            $stmt = $this->db->prepare("DELETE FROM `$table` WHERE user_id = :uid AND `$column` = :eid");
            $stmt->execute([':uid' => $user_id, ':eid' => $entity_id]);
            error_log("❌ Registration removed for user $user_id from $type $entity_id");
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
                error_log("✅ Event $uid " . ($operation === 'create' ? 'created' : 'updated'));
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM wp_events WHERE uid = :uid");
                $stmt->execute([':uid' => $uid]);
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
                error_log("✅ Session $uid " . ($operation === 'create' ? 'created' : 'updated'));
                break;
            case 'delete':
                $stmt = $this->db->prepare("DELETE FROM wp_sessions WHERE uid = :uid");
                $stmt->execute([':uid' => $uid]);
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
}

try {
    new UnifiedConsumer();
} catch (Exception $e) {
    error_log("❌ Consumer failed to start: " . $e->getMessage());
    exit(1);
}