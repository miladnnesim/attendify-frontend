<?php
namespace App;

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PDO;
use PDOStatement;
use Exception;
use DateTime;
use SimpleXMLElement;
use DOMDocument;

class CompanyProducer {
    private $connection;
    private $channel;
    private $exchange = 'company';

    public function __construct(AMQPChannel $channel = null) {
        if ($channel) {
            // ➕ testbare channel via dependency injection
            $this->channel = $channel;
        } else {
            // 🔌 verbind met RabbitMQ
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

    public function sendCompanyData(array $data, string $operation = 'create') {
        $xml = $this->buildXML($data, $operation);
        $msg = new AMQPMessage($xml, [
            'content_type' => 'application/xml',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);

        $routingKey = "company.$operation";
        $this->channel->basic_publish($msg, $this->exchange, $routingKey);

        $logMsg = "📤 [Producer] Sent message to RabbitMQ with routing key: $routingKey";
        error_log($logMsg);
        $this->sendMonitoringLog($logMsg, "info");
    }

    private function buildXML(array $data, string $operation): string {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>
<attendify xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="company_be.xsd" />');

        $info = $xml->addChild('info');
        $info->addChild('sender', 'frontend');
        $info->addChild('operation', $operation);

        $companies = $xml->addChild('companies');
        $company = $companies->addChild('company');

        // Nodig voor elke operatie
        $company->addChild('uid', htmlspecialchars($data['uid'] ?? ''));

        // Alleen bij create of update
        if (in_array($operation, ['create', 'update'])) {
            $company->addChild('companyNumber', htmlspecialchars($data['companyNumber'] ?? ''));
            $company->addChild('name', htmlspecialchars($data['name'] ?? ''));
            $company->addChild('VATNumber', htmlspecialchars($data['VATNumber'] ?? ''));

            $address = $company->addChild('address');
            $address->addChild('street', htmlspecialchars($data['street'] ?? ''));
            $address->addChild('number', htmlspecialchars($data['number'] ?? ''));
            $address->addChild('postcode', htmlspecialchars($data['postcode'] ?? ''));
            $address->addChild('city', htmlspecialchars($data['city'] ?? ''));

            $billingAddress = $company->addChild('billingAddress');
            $billingAddress->addChild('street', htmlspecialchars($data['billing_street'] ?? ''));
            $billingAddress->addChild('number', htmlspecialchars($data['billing_number'] ?? ''));
            $billingAddress->addChild('postcode', htmlspecialchars($data['billing_postcode'] ?? ''));
            $billingAddress->addChild('city', htmlspecialchars($data['billing_city'] ?? ''));

            $company->addChild('email', htmlspecialchars($data['email'] ?? ''));
            $company->addChild('phone', htmlspecialchars($data['phone'] ?? ''));

            // owner_id optioneel maar sterk aangeraden
            if (!empty($data['owner_id'])) {
                $company->addChild('owner_id', htmlspecialchars($data['owner_id']));
            }
        }

        return $xml->asXML();
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
        $sender = "frontend-company-producer";
        $timestamp = date('c');
        $logXml = "<log>"
            . "<sender>" . htmlspecialchars($sender) . "</sender>"
            . "<timestamp>" . htmlspecialchars($timestamp) . "</timestamp>"
            . "<level>" . htmlspecialchars($level) . "</level>"
            . "<message>" . htmlspecialchars($message) . "</message>"
            . "</log>";
        $amqpMsg = new AMQPMessage($logXml);
        // Exchange company, routing key monitoring.log
        $this->channel->basic_publish($amqpMsg, $this->exchange, 'monitoring.log');
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
