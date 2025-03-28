<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// Read operation from terminal input (default = create)
$operation = $argv[1] ?? 'create';

// Connect to RabbitMQ
$connection = new AMQPStreamConnection('localhost', 5672, 'attendify', 'uXe5u1oWkh32JyLA', 'attendify');
$channel = $connection->channel();

// Declare exchange (must match what exists in server!)
$exchange = 'user-management';
$channel->exchange_declare($exchange, 'direct', false, true, false);

// Prepare the XML message based on operation
$xmlMessage = '';
switch ($operation) {
    case 'create':
        $routingKey = 'user.register';
        $xmlMessage = <<<XML
<attendify>
  <info>
    <sender>frontend</sender>
    <operation>create</operation>
  </info>
  <user>
    <id>12345</id>
    <first_name>John</first_name>
    <last_name>Doe</last_name>
    <email>john@example.com</email>
    <title>Mr.</title>
    <date_of_birth>1990-01-01</date_of_birth>
    <phone_number>+3212345678</phone_number>
    <email_registered>true</email_registered>
  </user>
</attendify>
XML;
        break;

    case 'update':
        $routingKey = 'user.update';
        $xmlMessage = <<<XML
<attendify>
  <info>
    <sender>frontend</sender>
    <operation>update</operation>
  </info>
  <user>
    <id>12345</id>
    <first_name>Johnny</first_name>
    <last_name>Doe</last_name>
    <email>johnny.doe@example.com</email>
    <title>Dr.</title>
    <phone_number>+3212345679</phone_number>
    <email_registered>true</email_registered>
  </user>
</attendify>
XML;
        break;

    case 'delete':
        $routingKey = 'user.delete';
        $xmlMessage = <<<XML
<attendify>
  <info>
    <sender>frontend</sender>
    <operation>delete</operation>
  </info>
  <user>
    <id>12345</id>
  </user>
</attendify>
XML;
        break;

    default:
        echo "[!] Invalid operation. Use: create, update, or delete\n";
        exit;
}

// Send the message
$msg = new AMQPMessage($xmlMessage);
$channel->basic_publish($msg, $exchange, $routingKey);

echo "[x] Sent XML message with routing key '$routingKey'\n";

// Close connection
$channel->close();
$connection->close();
?>