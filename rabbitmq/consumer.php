<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection('rabbitmq', 5672, 'attendify', 'uXe5u1oWkh32JyLA', 'attendify');
$channel = $connection->channel();

// Declare the exchange (must match exactly!)
$exchange = 'user-management';
$channel->exchange_declare($exchange, 'direct', false, true, false);

// Declare queue and bind to all 3 routing keys
$queueName = 'frontend.user';
$channel->queue_declare($queueName, false, true, false, false);

// Bind queue to exchange with multiple routing keys
$routingKeys = ['user.register', 'user.update', 'user.delete'];
foreach ($routingKeys as $rk) {
    $channel->queue_bind($queueName, $exchange, $rk);
}

echo "[*] Waiting for messages on '$exchange'. To exit press CTRL+C\n";

// Callback to handle incoming messages
$callback = function ($msg) {
    echo "[x] Received message with routing key '{$msg->delivery_info['routing_key']}'\n";
    echo "-----------------------------\n";
    echo $msg->body . "\n";
    echo "=============================\n\n";
};

$channel->basic_consume($queueName, '', false, true, false, false, $callback);

// Keep listening as long as the channel is open
while ($channel->is_open()) {
    $channel->wait();
}

// Close everything
$channel->close();
$connection->close();
?>