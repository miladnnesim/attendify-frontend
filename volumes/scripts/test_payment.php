<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection(
    'rabbitmq', // host (container name)
    5672,
    'attendify',
    'uXe5u1oWkh32JyLA',
    'attendify'
);
$channel = $connection->channel();

// XML bericht (zorg dat velden matchen met je consumer_payment logic)
$xml = '<?xml version="1.0"?>
<attendify>
  <info>
    <sender>frontend</sender>
    <operation>create</operation>
  </info>
  <event_payment>
    <uid>PL1715012345678</uid>
    <event_id>EVT456</event_id>
    <entrance_fee>25.50</entrance_fee>
    <entrance_paid>true</entrance_paid>
    <paid_at>2025-05-06T14:35:00</paid_at>
  </event_payment>
</attendify>';

$msg = new AMQPMessage($xml, [
    'delivery_mode' => 2,
    'content_type' => 'application/xml'
]);

$channel->basic_publish($msg, '', 'betalingen_queue');
echo "[x] Testbetaling verzonden naar betalingen_queue\n";

$channel->close();
$connection->close();
