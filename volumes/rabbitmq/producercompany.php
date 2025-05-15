<?php

require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class CompanyProducer {
    private $connection;
    private $channel;
    private $exchange = 'company';

    public function __construct() {
        $this->connection = new AMQPStreamConnection(
            'rabbitmq',
            getenv('RABBITMQ_AMQP_PORT') ?: 5672,
            getenv('RABBITMQ_HOST') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_USER') ?: 'guest'
        );

        $this->channel = $this->connection->channel();

        // Declare the exchange (if it does not already exist)
        $this->channel->exchange_declare(
            $this->exchange,
            'direct',
            false,
            true,
            false
        );
    }

    /**
     * Sends company data to RabbitMQ
     *
     * @param array $data The company data to be sent
     * @param string $operation The type of operation (e.g., register, update, delete)
     */
    public function sendCompanyData(array $data, string $operation = 'register') {
        $xml = $this->buildXML($data, $operation);
        $msg = new AMQPMessage($xml, [
            'content_type' => 'application/xml',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
        ]);

        $routingKey = "company.$operation";

        $this->channel->basic_publish($msg, $this->exchange, $routingKey);

        error_log("📤 [Producer] Sent message to RabbitMQ with routing key: $routingKey");
    }

    /**
     * Builds an XML message that matches what the Consumer expects
     *
     * @param array $data The company data
     * @param string $operation The operation type
     * @return string XML message
     */
    private function buildXML(array $data, string $operation): string {
        $xml = new SimpleXMLElement('<root/>');

        $bedrijf = $xml->addChild('bedrijf');
        $bedrijf->addChild('ondernemingsNummer', htmlspecialchars($data['ondernemingsnummer']));
        $bedrijf->addChild('naam', htmlspecialchars($data['naam']));
        $bedrijf->addChild('btwNummer', htmlspecialchars($data['btwnummer']));

        $adres = $bedrijf->addChild('adres');
        $adres->addChild('straat', htmlspecialchars($data['straat']));
        $adres->addChild('nummer', htmlspecialchars($data['nummer']));
        $adres->addChild('postcode', htmlspecialchars($data['postcode']));
        $adres->addChild('gemeente', htmlspecialchars($data['gemeente']));

        $fAdres = $bedrijf->addChild('facturatieAdres');
        $fAdres->addChild('straat', htmlspecialchars($data['facturatie_straat']));
        $fAdres->addChild('nummer', htmlspecialchars($data['facturatie_nummer']));
        $fAdres->addChild('postcode', htmlspecialchars($data['facturatie_postcode']));
        $fAdres->addChild('gemeente', htmlspecialchars($data['facturatie_gemeente']));

        $bedrijf->addChild('email', htmlspecialchars($data['email']));
        $bedrijf->addChild('telefoon', htmlspecialchars($data['telefoon']));

        $info = $xml->addChild('info');
        $info->addChild('operation', $operation);

        return $xml->asXML();
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