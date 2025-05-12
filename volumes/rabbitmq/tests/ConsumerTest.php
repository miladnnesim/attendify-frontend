<?php
use PHPUnit\Framework\TestCase;
require_once __DIR__ . '/../consumer.php';
require_once __DIR__ . '/../vendor/autoload.php';

class RabbitMQ_Consumer_Testable extends RabbitMQ_Consumer {
    // We exposeren de methode createUser zodat we die kunnen testen
    public function publicCreateUser(SimpleXMLElement $userNode, $sender) {
        return $this->createUser($userNode, $sender);
    }

    // We injecteren een valse PDO voor de tests
    public function setPDO($pdo) {
        $this->pdo = $pdo;
    }
}

class ConsumerTest extends TestCase {

    private $consumer;
    private $pdoMock;

    protected function setUp(): void {
        // We maken een mock van de PDO-klasse
        $this->pdoMock = $this->createMock(PDO::class);

        // We initialiseren de testbare klasse
        $this->consumer = new RabbitMQ_Consumer_Testable();
        $this->consumer->setPDO($this->pdoMock);
    }

    public function testCreateUserInsertsUserCorrectly() {
        // We bereiden een valse XML-invoer voor
        $xmlString = <<<XML
<user>
    <uid>1234</uid>
    <email>john.doe@example.com</email>
    <first_name>John</first_name>
    <last_name>Doe</last_name>
    <password>secret123</password>
</user>
XML;

        $userNode = new SimpleXMLElement($xmlString);
        $sender = 'CRM';

        // We bereiden de mock voor de PDO voorbereid statements
        $statementMock = $this->createMock(PDOStatement::class);

        // We verwachten dat PDO::prepare eenmaal wordt aangeroepen
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO wp_users'))
            ->willReturn($statementMock);

        // Het statement moet worden uitgevoerd met specifieke parameters
        $statementMock->expects($this->once())
            ->method('execute')
            ->with($this->callback(function ($params) {
                return $params[':user_login'] === '1234'
                    && $params[':user_email'] === 'john.doe@example.com'
                    && $params[':user_pass'] !== ''; 
            }))
            ->willReturn(true);

        // We roepen de te testen methode aan
        $result = $this->consumer->publicCreateUser($userNode, $sender);

        // We controleren of de methode een verwacht resultaat retourneert (bijv. true of id)
        $this->assertNotNull($result);
    }
}
