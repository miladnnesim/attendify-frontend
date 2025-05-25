<?php
namespace App;
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PDO;
use PDOStatement;
use Exception;
use DateTime;
use SimpleXMLElement;
use DOMDocument;

function sendRegistrationMessage($type, $user_id, $entity_id, $operation = 'register') {
    $producer = new RegistrationMessageProducer();
    $producer->sendRegistrationMessage($type, $user_id, $entity_id, $operation);
}

class RegistrationMessageProducer {
    private $channel;
    private $connection;

    public function __construct(AMQPChannel $channel = null) {
        if ($channel) {
            // ➕ testbare channel
            $this->channel = $channel;
        } else {
            // 🔌 connectie met RabbitMQ
            $this->connection = new AMQPStreamConnection(
                'rabbitmq',
                getenv('RABBITMQ_AMQP_PORT'),
                getenv('RABBITMQ_HOST'),
                getenv('RABBITMQ_PASSWORD'),
                getenv('RABBITMQ_USER')
            );
            $this->channel = $this->connection->channel();
        }
    }

    public function sendRegistrationMessage($type, $user_id, $entity_id, $operation = 'register') {
        if (!in_array($type, ['event', 'session'])) {
            throw new Exception("❌ Onbekend type '$type'. Moet 'event' of 'session' zijn.");
        }

        $exchange = $type; // 'event' of 'session'
        $routing_key = "$type.$operation"; // bv. 'event.register' of 'session.delete'

        if ($type === 'event') {
            $xml = <<<XML
            <attendify>
              <info>
                <operation>$operation</operation>
                <sender>frontend</sender>
              </info>
              <event_attendee>
                <uid>$user_id</uid>
                <event_id>$entity_id</event_id>
              </event_attendee>
            </attendify>
            XML;
        } else { // $type === 'session'
            $xml = <<<XML
            <attendify>
              <info>
                <operation>$operation</operation>
                <sender>frontend</sender>
              </info>
              <session_attendee>
                <uid>$user_id</uid>
                <session_id>$entity_id</session_id>
              </session_attendee>
            </attendify>
            XML;
        }

        $msg = new AMQPMessage($xml, [
            'content_type' => 'text/xml',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);

        $this->channel->basic_publish($msg, $exchange, $routing_key);
        $logMsg = "📤 [$operation] gestuurd voor $type '$entity_id' van user '$user_id'";
        error_log($logMsg);
        $this->sendMonitoringLog($logMsg, "info");
    }

    private function sendMonitoringLog(string $message, string $level = "info") {
        if (!$this->channel) {
            error_log("[monitoring.log skipped]: $message");
            return;
        }
        $sender = "frontend-registration-producer";
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

    public function __destruct() {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
    }
}
