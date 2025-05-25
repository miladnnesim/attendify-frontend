<?php
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class InvoiceConsumer {
    private $connection;
    private $channel;
    private $db;
    private $queues = ['frontend.invoice', 'frontend.sale'];

    public function __construct(?PDO $db = null, ?AMQPChannel $channel = null) {
        $this->db = $db ?? $this->createDbConnection();
        $this->channel = $channel ?? $this->connectToRabbitMQ();
    }

    public function run(): void {
        $this->initTables();
        $this->consume();
    }

    private function createDbConnection(): PDO {
        $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
        $pdo = new PDO($dsn, getenv('LOCAL_DB_USER') ?: 'root', getenv('LOCAL_DB_PASSWORD') ?: 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        error_log("✅ Verbonden met database");
        return $pdo;
    }

    private function initTables() {
        // event_payments
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS event_payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid VARCHAR(30) NOT NULL,
                event_id VARCHAR(30) NOT NULL,
                entrance_fee DECIMAL(10,2) NOT NULL,
                entrance_paid TINYINT(1) NOT NULL,
                paid_at DATETIME NOT NULL,
                UNIQUE KEY unique_uid_event (uid, event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->verifyColumns('event_payments', ['uid', 'event_id', 'entrance_fee', 'entrance_paid', 'paid_at']);

        // tab_sales
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tab_sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid VARCHAR(30) NOT NULL,
                event_id VARCHAR(30) NOT NULL,
                timestamp DATETIME NOT NULL,
                is_paid TINYINT(1) NOT NULL,
                UNIQUE KEY unique_tab (uid, event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->verifyColumns('tab_sales', ['uid', 'event_id', 'timestamp', 'is_paid']);

        // tab_items
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS tab_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                tab_id INT NOT NULL,
                item_name VARCHAR(255) NOT NULL,
                quantity INT NOT NULL,
                price DECIMAL(10,2) NOT NULL,
                FOREIGN KEY (tab_id) REFERENCES tab_sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->verifyColumns('tab_items', ['tab_id', 'item_name', 'quantity', 'price']);
    }

    private function verifyColumns($table, $requiredCols) {
        foreach ($requiredCols as $col) {
            $stmt = $this->db->query("SHOW COLUMNS FROM $table LIKE '$col'");
            if ($stmt->rowCount() === 0) {
                error_log("❗ Kolom '$col' ontbreekt in '$table'");
            }
        }
    }

    private function connectToRabbitMQ(): AMQPChannel {
        $this->connection = new AMQPStreamConnection(
            'rabbitmq',
            getenv('RABBITMQ_AMQP_PORT') ?: 5672,
            getenv('RABBITMQ_HOST') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_USER') ?: 'guest'
        );
        $channel = $this->connection->channel();
        $channel->basic_qos(null, 1, null);
        error_log("✅ Verbonden met RabbitMQ");
        return $channel;
    }

    private function consume() {
        foreach ($this->queues as $queue) {
            $this->channel->basic_consume($queue, '', false, false, false, false, [$this, 'handleMessage']);
            error_log("🎧 Luistert naar queue: $queue");
        }

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }


    public function handleMessage(AMQPMessage $msg) {
        try {
            $xml = simplexml_load_string($msg->body);
            if (!$xml) throw new Exception("❌ Ongeldig XML-formaat");

            $sender = (string) $xml->info->sender;
            if (strtolower($sender) === 'frontend') {
                error_log("ℹ️ Sender is frontend, message genegeerd.");
                $msg->ack();
                return;
            }

            $operation = (string) $xml->info->operation;

            if (isset($xml->event_payment)) {
                $this->handleEventPayment($xml->event_payment, $operation);
            }

            if (isset($xml->tab)) {
                $this->handleTab($xml->tab, $operation);
            }

            $msg->ack();
        } catch (Exception $e) {
            error_log("[ERROR] " . $e->getMessage());
            $msg->ack();
        }
    }

    private function handleEventPayment(SimpleXMLElement $payment, string $operation) {
        $uid = (string) $payment->uid;
        $event_id = (string) $payment->event_id;

        if (!$uid || !$event_id) throw new Exception("❌ uid of event_id ontbreekt");

        $check = $this->db->prepare("SELECT COUNT(*) FROM event_payments WHERE uid = :uid AND event_id = :event_id");
        $check->execute([':uid' => $uid, ':event_id' => $event_id]);
        $exists = $check->fetchColumn() > 0;

        if ($operation === 'create_event_payment') {
            if ($exists) throw new Exception("❌ Betaling bestaat al ($uid, $event_id)");

            $stmt = $this->db->prepare("
                INSERT INTO event_payments (uid, event_id, entrance_fee, entrance_paid, paid_at)
                VALUES (:uid, :event_id, :entrance_fee, :entrance_paid, :paid_at)
            ");
        } elseif ($operation === 'update_event_payment') {
            if (!$exists) {
                error_log("❌ Geen bestaande betaling voor update ($uid, $event_id)");
                return;
            }

            $stmt = $this->db->prepare("
                UPDATE event_payments
                SET entrance_fee = :entrance_fee,
                    entrance_paid = :entrance_paid,
                    paid_at = :paid_at
                WHERE uid = :uid AND event_id = :event_id
            ");
        } elseif ($operation === 'delete_event_payment') {
            if (!$exists) {
                error_log("❌ Geen bestaande betaling voor delete ($uid, $event_id)");
                return;
            }

            $stmt = $this->db->prepare("DELETE FROM event_payments WHERE uid = :uid AND event_id = :event_id");
            $stmt->execute([':uid' => $uid, ':event_id' => $event_id]);
            return;
        } else {
            throw new Exception("❌ Ongeldige operatie voor event_payment: $operation");
        }

        $stmt->execute([
            ':uid' => $uid,
            ':event_id' => $event_id,
            ':entrance_fee' => (float) $payment->entrance_fee,
            ':entrance_paid' => ((string)$payment->entrance_paid === 'true') ? 1 : 0,
            ':paid_at' => (string) $payment->paid_at
        ]);
    }

    private function handleTab(SimpleXMLElement $tab, string $operation) {
        $uid = (string) $tab->uid;
        $event_id = (string) $tab->event_id;

        if (!$uid || !$event_id) throw new Exception("❌ uid of event_id ontbreekt bij tab");

        $check = $this->db->prepare("SELECT id FROM tab_sales WHERE uid = :uid AND event_id = :event_id");
        $check->execute([':uid' => $uid, ':event_id' => $event_id]);
        $tab_id = $check->fetchColumn();

        if ($operation === 'create') {
            if ($tab_id) throw new Exception("❌ Tab bestaat al voor ($uid, $event_id)");

            $insertTab = $this->db->prepare("
                INSERT INTO tab_sales (uid, event_id, timestamp, is_paid)
                VALUES (:uid, :event_id, :timestamp, :is_paid)
            ");
            $insertTab->execute([
                ':uid' => $uid,
                ':event_id' => $event_id,
                ':timestamp' => (string) $tab->timestamp,
                ':is_paid' => ((string) $tab->is_paid === 'true') ? 1 : 0
            ]);
            $tab_id = $this->db->lastInsertId();
        } elseif ($operation === 'update') {
            if (!$tab_id) {
                error_log("❌ Geen bestaande tab voor update ($uid, $event_id)");
                return;
            }

            $updateTab = $this->db->prepare("
                UPDATE tab_sales
                SET timestamp = :timestamp, is_paid = :is_paid
                WHERE id = :id
            ");
            $updateTab->execute([
                ':timestamp' => (string) $tab->timestamp,
                ':is_paid' => ((string) $tab->is_paid === 'true') ? 1 : 0,
                ':id' => $tab_id
            ]);

            $this->db->prepare("DELETE FROM tab_items WHERE tab_id = :tab_id")->execute([':tab_id' => $tab_id]);
        } elseif ($operation === 'delete') {
            if (!$tab_id) {
                error_log("❌ Geen bestaande tab voor delete ($uid, $event_id)");
                return;
            }

            $this->db->prepare("DELETE FROM tab_sales WHERE id = :id")->execute([':id' => $tab_id]);
            return;
        } else {
            throw new Exception("❌ Ongeldige operatie voor tab: $operation");
        }

        if ($operation === 'create' || $operation === 'update') {
            foreach ($tab->items->tab_item as $item) {
                $insertItem = $this->db->prepare("
                    INSERT INTO tab_items (tab_id, item_name, quantity, price)
                    VALUES (:tab_id, :item_name, :quantity, :price)
                ");
                $insertItem->execute([
                    ':tab_id' => $tab_id,
                    ':item_name' => (string) $item->item_name,
                    ':quantity' => (int) $item->quantity,
                    ':price' => (float) $item->price
                ]);
            }
        }
    }
}

try {
    $consumer = new InvoiceConsumer();
    $consumer->run();
} catch (Exception $e) {
    error_log("❌ InvoiceConsumer kon niet starten: " . $e->getMessage());
    exit(1);
}
