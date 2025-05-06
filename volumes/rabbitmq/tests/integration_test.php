<?php

require_once '/var/www/html/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PDO;

function sendTestMessage() {
    $connection = new AMQPStreamConnection('rabbitmq', getenv('RABBITMQ_AMQP_PORT'), getenv('RABBITMQ_HOST'), getenv('RABBITMQ_PASSWORD'), getenv('RABBITMQ_USER'));
    $channel = $connection->channel();
    
    $exchange = 'user-management';
    $routingKey = 'frontend.user';
    
    $messageBody = '<message>
        <info>
            <operation>create</operation>
            <sender>TestScript</sender>
        </info>
        <user>
            <uid>TEST123</uid>
            <email>test.integration@example.com</email>
            <first_name>Integration</first_name>
            <last_name>Test</last_name>
            <password>securePass123</password>
        </user>
    </message>';
    
    $msg = new AMQPMessage($messageBody);
    $channel->basic_publish($msg, $exchange, $routingKey);

    echo "Message sent!\n";
    
    $channel->close();
    $connection->close();
}

function verifyUserCreated() {
    $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
    $db = new PDO($dsn, getenv('LOCAL_DB_USER'), getenv('LOCAL_DB_PASSWORD'));
    
    $query = "SELECT ID FROM wp_users WHERE user_email = :email";
    $stmt = $db->prepare($query);
    $stmt->execute([':email' => 'test.integration@example.com']);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        echo "✅ Test passed: User created successfully with ID " . $user['ID'] . "\n";
    } else {
        echo "❌ Test failed: User not found.\n";
    }
}

// Execute the integration test
sendTestMessage();
sleep(5); // Wait for message processing
verifyUserCreated();

?>
