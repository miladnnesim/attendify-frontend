<?php
namespace App;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use InvalidArgumentException;
use DOMDocument;
use SimpleXMLElement;

class UserCompanyLinkProducer {
    private AMQPChannel $channel;
    private string $exchange = 'company';

    /**
     * Constructor expects a pre-configured AMQPChannel for testability and to avoid real network calls in tests.
     */
    public function __construct(AMQPChannel $channel) {
        $this->channel = $channel;
    }

    /**
     * Send a company link/unlink message to RabbitMQ.
     *
     * @param string $user_uid
     * @param string $company_uid
     * @param string $operation 'register' or 'unregister'
     * @throws InvalidArgumentException on unknown operation
     */
    public function send(string $user_uid, string $company_uid, string $operation = 'register'): void {
        // Determine routing key based on operation
        if ($operation === 'register') {
            $routingKey = 'company.register';
        } elseif ($operation === 'unregister') {
            $routingKey = 'company.unregister';
        } else {
            throw new InvalidArgumentException("Ongeldige operatie: $operation");
        }

        // Build XML message
        $xml = new SimpleXMLElement('<attendify/>');
        $info = $xml->addChild('info');
        $info->addChild('sender', 'frontend');
        $info->addChild('operation', $operation);

        $employee = $xml->addChild('company_employee');
        $employee->addChild('uid', $user_uid);
        $employee->addChild('company_id', $company_uid);

        // Pretty-format XML
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());
        $xmlString = $dom->saveXML();

        // Publish message
        $message = new AMQPMessage($xmlString, ['content_type' => 'text/xml']);
        $this->channel->basic_publish($message, $this->exchange, $routingKey);

        error_log("✅ XML verzonden naar RabbitMQ voor user '{$user_uid}' met operatie '{$operation}' via routing '{$routingKey}'");
    }
}

/**
 * Helper function for backward compatibility or simple CLI usage.
 *
 * Note: In tests, prefer injecting your own channel into UserCompanyLinkProducer.
 */
function sendUserCompanyLink(string $user_uid, string $company_uid, string $operation = 'register') {
    // Create a real connection only in production
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

    // Close connections
    $channel->close();
    $connection->close();
}

// CLI fallback
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $user_uid    = $argv[1] ?? 'u1';
    $company_uid = $argv[2] ?? 'e5';
    $operation   = $argv[3] ?? 'register';
    sendUserCompanyLink($user_uid, $company_uid, $operation);
}
