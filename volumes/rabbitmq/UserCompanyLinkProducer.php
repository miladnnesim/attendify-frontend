<?php
namespace App;
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use InvalidArgumentException;
use DOMDocument;
use SimpleXMLElement;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class UserCompanyLinkProducer {
    private AMQPChannel $channel;
    private string $exchange = 'company';

    public function __construct(AMQPChannel $channel) {
        $this->channel = $channel;
    }

    public function send(string $user_uid, string $company_uid, string $operation = 'register'): void {
        if ($operation === 'register') {
            $routingKey = 'company.register';
        } elseif ($operation === 'unregister') {
            $routingKey = 'company.unregister';
        } else {
            throw new InvalidArgumentException("Ongeldige operatie: $operation");
        }

        $xml = new SimpleXMLElement('<attendify/>');
        $info = $xml->addChild('info');
        $info->addChild('sender', 'frontend');
        $info->addChild('operation', $operation);

        $employee = $xml->addChild('company_employee');
        $employee->addChild('uid', $user_uid);
        $employee->addChild('company_id', $company_uid);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $xmlString = $dom->saveXML();

        $message = new AMQPMessage($xmlString, ['content_type' => 'text/xml']);
        $this->channel->basic_publish($message, $this->exchange, $routingKey);

        $logMsg = "✅ XML verzonden naar RabbitMQ voor user '{$user_uid}' met operatie '{$operation}' via routing '{$routingKey}'";
        error_log($logMsg);
        $this->sendMonitoringLog($logMsg, "info");
    }

    private function sendMonitoringLog(string $message, string $level = "info") {
        if (!$this->channel) {
            error_log("[monitoring.log skipped]: $message");
            return;
        }
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
        // Tijdens unit tests: skip publish naar monitoring
        return;
    }
        $sender = "frontend-user-company-link-producer";
        $timestamp = date('c');
        $logXml = "<log>"
            . "<sender>" . htmlspecialchars($sender) . "</sender>"
            . "<timestamp>" . htmlspecialchars($timestamp) . "</timestamp>"
            . "<level>" . htmlspecialchars($level) . "</level>"
            . "<message>" . htmlspecialchars($message) . "</message>"
            . "</log>";
        $amqpMsg = new AMQPMessage($logXml);
        $this->channel->basic_publish($amqpMsg, $this->exchange, 'monitoring.log');
    }
}

function sendUserCompanyLink(string $user_uid, string $company_uid, string $operation = 'register') {
    $connection = new AMQPStreamConnection(
        'rabbitmq',
        (int)(getenv('RABBITMQ_AMQP_PORT') ?: 5672),
        getenv('RABBITMQ_HOST') ?: 'guest',
        getenv('RABBITMQ_PASSWORD') ?: 'guest',
        getenv('RABBITMQ_USER') ?: 'guest'
    );
    $channel = $connection->channel();

    $producer = new UserCompanyLinkProducer($channel);
    $producer->send($user_uid, $company_uid, $operation);

    $channel->close();
    $connection->close();
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $user_uid    = $argv[1] ?? 'u1';
    $company_uid = $argv[2] ?? 'e5';
    $operation   = $argv[3] ?? 'register';
    sendUserCompanyLink($user_uid, $company_uid, $operation);
}
