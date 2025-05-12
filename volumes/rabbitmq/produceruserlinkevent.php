<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendRegistrationMessage($type, $user_id, $entity_id, $operation = 'create') {
    $exchange = 'event';
    $routing_key = 'event.register';

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
  </info>
  <event_attendee>
    <uid>$user_id</uid>
    <event_id>$entity_id</event_id>
  </event_attendee>
</attendify>
XML;
    } elseif ($type === 'session') {
        $xml = <<<XML
<attendify>
  <info>
    <operation>$operation</operation>
  </info>
  <session_attendee>
    <uid>$user_id</uid>
    <session_id>$entity_id</session_id>
  </session_attendee>
</attendify>
XML;
    } else {
        throw new Exception("❌ Onbekend type '$type'. Moet 'event' of 'session' zijn.");
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

