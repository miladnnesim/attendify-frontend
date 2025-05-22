<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendRegistrationMessage($type, $user_id, $entity_id, $operation = 'register') {
    if (!in_array($type, ['event', 'session'])) {
        throw new Exception("❌ Onbekend type '$type'. Moet 'event' of 'session' zijn.");
    }

    $exchange = $type; // 'event' of 'session'
    $routing_key = "$type.$operation"; // bv. 'event.register' of 'session.delete'

    $connection = new AMQPStreamConnection(
        'rabbitmq',
        getenv('RABBITMQ_AMQP_PORT'),
        getenv('RABBITMQ_HOST'),
        getenv('RABBITMQ_PASSWORD'),
        getenv('RABBITMQ_USER')
    );
    $channel = $connection->channel();

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

    $channel->basic_publish($msg, $exchange, $routing_key);
    error_log("📤 [$operation] gestuurd voor $type '$entity_id' van user '$user_id'");

    $channel->close();
    $connection->close();
}

