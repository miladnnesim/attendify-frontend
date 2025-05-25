<?php
namespace App;
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class UserCompanyLinkProducer {
    private $channel;
    private $connection;
    private $exchange = 'company';

    public function __construct(AMQPChannel $channel = null) {
        if ($channel) {
            // ➕ testbare channel via DI
            $this->channel = $channel;
        } else {
            // 🔌 echte connectie
            $this->connection = new AMQPStreamConnection(
                'rabbitmq',
                getenv('RABBITMQ_AMQP_PORT') ?: 5672,
                getenv('RABBITMQ_HOST') ?: 'guest',
                getenv('RABBITMQ_PASSWORD') ?: 'guest',
                getenv('RABBITMQ_USER') ?: 'guest'
            );
            $this->channel = $this->connection->channel();
        }
    }

    public function send(string $user_uid, string $company_uid, string $operation = 'register'): void {
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

        $this->channel->basic_publish($message, $this->exchange, $routing_key);

        error_log("✅ XML verzonden naar RabbitMQ voor user '{$user_uid}' met operatie '{$operation}' via routing '{$routing_key}'");
    }

    public function __destruct() {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

// ❇️ Handige wrapper functie behouden (voor backward compatibility of WordPress gebruik)
function sendUserCompanyLink($user_uid, $company_uid, $operation = 'register') {
    $producer = new UserCompanyLinkProducer();
    $producer->send($user_uid, $company_uid, $operation);
}

// CLI test
if (php_sapi_name() === 'cli') {
    $user_uid = $argv[1] ?? 'u1';
    $company_uid = $argv[2] ?? 'e5';
    $operation = $argv[3] ?? 'register';
    sendUserCompanyLink($user_uid, $company_uid, $operation);
}
