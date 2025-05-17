<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

function sendUserCompanyLink($user_uid, $company_uid, $operation = 'register') {
    $connection = new AMQPStreamConnection(
        'rabbitmq',
        getenv('RABBITMQ_AMQP_PORT') ?: 5672,
        getenv('RABBITMQ_HOST') ?: 'guest',
        getenv('RABBITMQ_PASSWORD') ?: 'guest',
        getenv('RABBITMQ_USER') ?: 'guest'
    );

    $channel = $connection->channel();
    $exchange = 'company';

    // ✅ Routing key dynamisch bepalen op basis van operatie
    if ($operation === 'register') {
        $routing_key = 'company.register';
    } elseif ($operation === 'unregister') {
        $routing_key = 'company.unregister';
    } else {
        throw new InvalidArgumentException("Ongeldige operatie: $operation");
    }

    // XML bericht bouwen
    $xml = new SimpleXMLElement('<attendify/>');
    $info = $xml->addChild('info');
    $info->addChild('sender', 'frontend');
    $info->addChild('operation', $operation);

    $employee = $xml->addChild('company_employee');
    $employee->addChild('uid', $user_uid);
    $employee->addChild('company_id', $company_uid);

    $dom = new DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());
    $xml_string = $dom->saveXML();

    $message = new AMQPMessage($xml_string, ['content_type' => 'text/xml']);

    $channel->basic_publish($message, $exchange, $routing_key);

    error_log("✅ XML verzonden naar RabbitMQ voor user '{$user_uid}' met operatie '{$operation}' via routing '{$routing_key}'");

    $channel->close();
    $connection->close();
}

// CLI test
if (php_sapi_name() === 'cli') {
    $user_uid = $argv[1] ?? 'u1';
    $company_uid = $argv[2] ?? 'e5';
    $operation = $argv[3] ?? 'register';
    sendUserCompanyLink($user_uid, $company_uid, $operation);
}
