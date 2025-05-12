<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;

// Verbind met database
$db = new PDO(
    'mysql:host=' . getenv('LOCAL_DB_HOST') . ';dbname=' . getenv('LOCAL_DB_NAME'),
    getenv('LOCAL_DB_USER'),
    getenv('LOCAL_DB_PASSWORD')
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Verbind met RabbitMQ
$connection = new AMQPStreamConnection(
    'rabbitmq', 5672, 'attendify', 'uXe5u1oWkh32JyLA', 'attendify'
);
$channel = $connection->channel();

$channel->queue_declare('betalingen_queue', false, true, false, false);

echo "[*] Wacht op berichten in betalingen_queue. Druk op CTRL+C om te stoppen\n";

$callback = function ($msg) use ($db) {
    echo "[x] Ontvangen bericht:\n";
    echo $msg->body . "\n";

    try {
        $xml = new SimpleXMLElement($msg->body);

        $operation = (string)$xml->info->operation;
        $payment = $xml->event_payment;

        $uid = (string)$payment->uid;
        $eventId = (string)$payment->event_id;
        $entranceFee = (float)$payment->entrance_fee;
        $entrancePaid = ((string)$payment->entrance_paid === 'true') ? 1 : 0;
        $paidAt = isset($payment->paid_at) ? (string)$payment->paid_at : null;

        if ($operation === 'create') {
            $query = "INSERT INTO event_payments (uid, event_id, entrance_fee, entrance_paid, paid_at)
                      VALUES (:uid, :event_id, :entrance_fee, :entrance_paid, :paid_at)";
        } elseif ($operation === 'update') {
            $query = "UPDATE event_payments
                      SET entrance_fee = :entrance_fee,
                          entrance_paid = :entrance_paid,
                          paid_at = :paid_at
                      WHERE uid = :uid AND event_id = :event_id";
        } else {
            throw new Exception("Onbekende operatie: $operation");
        }

        $stmt = $db->prepare($query);
        $stmt->execute([
            ':uid' => $uid,
            ':event_id' => $eventId,
            ':entrance_fee' => $entranceFee,
            ':entrance_paid' => $entrancePaid,
            ':paid_at' => $paidAt
        ]);

        echo "✅ Betaling verwerkt: $uid voor event $eventId\n";
    } catch (Exception $e) {
        echo "❌ Fout bij verwerking betaling: " . $e->getMessage() . "\n";
    }
};

$channel->basic_consume('betalingen_queue', '', false, true, false, false, $callback);

while ($channel->is_consuming()) {
    $channel->wait();
}
