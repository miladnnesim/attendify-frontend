<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

$connection = new AMQPStreamConnection(
    'rabbitmq', 5672, 'attendify', 'uXe5u1oWkh32JyLA', 'attendify'
);
$channel = $connection->channel();

$channel->queue_declare('betalingen_queue', false, true, false, false);

echo "[*] Wacht op berichten in betalingen_queue. Druk op CTRL+C om te stoppen\n";

$callback = function ($msg) {
    echo "[x] Ontvangen bericht:\n";
    echo $msg->body . "\n";

    // Hier schrijf je de XML-parsing en insert naar de betalingen-tabel
    // Tip: gebruik SimpleXMLElement om XML te parsen
};

$channel->basic_consume('betalingen_queue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}
