<?php
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RegistrationConsumer {
    private $connection;
    private $channel;
    private $db;

    public function __construct() {
        $this->connectToDB();
        $this->initTable('user_event', [
            'user_id' => 'VARCHAR(255)',
            'event_id' => 'VARCHAR(255)'
        ], ['user_id', 'event_id']);

        $this->initTable('user_session', [
            'user_id' => 'VARCHAR(255)',
            'session_id' => 'VARCHAR(255)'
        ], ['user_id', 'session_id']);

        $this->connectToRabbitMQ();
        $this->listen();
    }

    private function connectToDB() {
        try {
            $this->db = new PDO("mysql:host=db;dbname=wordpress;charset=utf8mb4", 'root', 'root');
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            error_log("✅ Verbonden met database");
        } catch (PDOException $e) {
            error_log("❌ Databasefout: " . $e->getMessage());
            exit(1);
        }
    }

    private function initTable($table, $fields, $primaryKeys) {
        // Maak tabel aan als die nog niet bestaat
        $columns = [];
        foreach ($fields as $col => $type) {
            $columns[] = "`$col` $type NOT NULL";
        }
        $pk = implode(', ', array_map(fn($col) => "`$col`", $primaryKeys));
        $query = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(', ', $columns) . ", PRIMARY KEY ($pk)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $this->db->exec($query);
        error_log("✅ Tabel '$table' gecontroleerd of aangemaakt");

        // Controleer of kolommen ontbreken
        $stmt = $this->db->prepare("SHOW COLUMNS FROM `$table`");
        $stmt->execute();
        $existing = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        foreach ($fields as $col => $type) {
            if (!in_array($col, $existing)) {
                $alter = "ALTER TABLE `$table` ADD COLUMN `$col` $type NOT NULL";
                $this->db->exec($alter);
                error_log("➕ Kolom '$col' toegevoegd aan '$table'");
            }
        }
    }

    private function connectToRabbitMQ() {
        $this->connection = new AMQPStreamConnection(
            'rabbitmq',
            getenv('RABBITMQ_AMQP_PORT'),
            getenv('RABBITMQ_HOST'),
            getenv('RABBITMQ_PASSWORD'),
            getenv('RABBITMQ_USER')
        );
        $this->channel = $this->connection->channel();
        error_log("✅ Verbonden met RabbitMQ");
    }

    private function listen() {
        $queues = ['frontend.event', 'frontend.session'];
        foreach ($queues as $queue) {
        $this->channel->basic_consume($queue, '', false, false, false, false, $callback);
        }

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function handleMessage(AMQPMessage $msg) {
        try {
            $xml = simplexml_load_string($msg->body);
            if (!$xml) throw new Exception("Ongeldig XML-formaat");

            $operation = (string)$xml->info->operation;

            if (isset($xml->event_attendee)) {
                $user_id = trim((string)$xml->event_attendee->uid);
                $event_id = trim((string)$xml->event_attendee->event_id);
                if (!$user_id || !$event_id) throw new Exception("user_id of event_id ontbreekt");
                $this->processRegistration('event', $operation, $user_id, $event_id);

            } elseif (isset($xml->session_attendee)) {
                $user_id = trim((string)$xml->session_attendee->uid);
                $session_id = trim((string)$xml->session_attendee->session_id);
                if (!$user_id || !$session_id) throw new Exception("user_id of session_id ontbreekt");
                $this->processRegistration('session', $operation, $user_id, $session_id);

            } else {
                throw new Exception("Onbekend berichttype (geen event_attendee of session_attendee)");
            }

            $msg->ack();
        } catch (Exception $e) {
            error_log("[FOUT] " . $e->getMessage());
            $msg->ack(); // message wordt niet opnieuw gepushed
        }
    }

    private function processRegistration($type, $operation, $user_id, $entity_id) {
        $table = $type === 'event' ? 'user_event' : 'user_session';
        $column = $type === 'event' ? 'event_id' : 'session_id';

        if ($operation === 'register') {
            $stmt = $this->db->prepare("INSERT IGNORE INTO `$table` (user_id, `$column`) VALUES (:uid, :eid)");
            $stmt->execute([':uid' => $user_id, ':eid' => $entity_id]);
            error_log("✅ Gebruiker $user_id geregistreerd voor $type $entity_id");

        } elseif ($operation === 'unregister') {
            $stmt = $this->db->prepare("DELETE FROM `$table` WHERE user_id = :uid AND `$column` = :eid");
            $stmt->execute([':uid' => $user_id, ':eid' => $entity_id]);
            error_log("❌ Registratie verwijderd van gebruiker $user_id voor $type $entity_id");

        } else {
            throw new Exception("❓ Onbekende operatie: $operation");
        }
    }
}

try {
    new RegistrationConsumer();
} catch (Exception $e) {
    error_log("❌ Consumer crashte: " . $e->getMessage());
    exit(1);
}
