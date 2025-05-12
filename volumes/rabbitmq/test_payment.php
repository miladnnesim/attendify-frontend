<?php
require_once __DIR__.'/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$connection = new AMQPStreamConnection(
    'rabbitmq',
    5672,
    'attendify',
    'uXe5u1oWkh32JyLA',
    'attendify'
);

$channel = $connection->channel();
$exchange = 'user-management';

$xml = '<?xml version="1.0"?>
<attendify>
  <info>
    <sender>frontend</sender>
    <operation>payment</operation>
  </info>
  <payment>
    <user_id>123</user_id>
    <amount>49.99</amount>
    <method>Credit Card</method>
    <status>paid</status>
  </payment>
</attendify>';

$msg = new AMQPMessage($xml, [
    'delivery_mode' => 2,
    'content_type' => 'application/xml'
]);

$channel->basic_publish($msg, $exchange, 'user.payment');

echo " [x] Testbetaling verzonden!\n";

$channel->close();
$connection->close();
