<?php
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Message\AMQPMessage;

require_once 'C:/Users/yassi/OneDrive/Bureaublad/attendify-frontend/volumes/rabbitmq/consumer.php';

class ConsumerTest extends TestCase
{
    private $consumer;

    protected function setUp(): void
    {
        // On mock PDO avec un stub pour éviter la vraie DB
        $pdoMock = $this->createMock(PDO::class);
        
        // Création d'une instance du consumer sans exécuter processMessages
        $this->consumer = $this->getMockBuilder(RabbitMQ_Consumer::class)
                               ->disableOriginalConstructor()
                               ->onlyMethods(['connectRabbitMQ', 'setupQueue', 'processMessages'])
                               ->getMock();

        // Injecter une fausse connexion DB (ou autre si tu veux tester handleMessage indépendamment)
        $reflection = new ReflectionClass($this->consumer);
        $prop = $reflection->getProperty('db');
        $prop->setAccessible(true);
        $prop->setValue($this->consumer, $pdoMock);
    }

    public function testHandleMessageWithInvalidXml()
    {
        $message = new AMQPMessage('<invalid><xml>');
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Ongeldig XML-formaat');

        // Appel à la méthode protégée via réflexion
        $method = new ReflectionMethod($this->consumer, 'handleMessage');
        $method->setAccessible(true);
        $method->invoke($this->consumer, $message, 'CRM');
    }
}
