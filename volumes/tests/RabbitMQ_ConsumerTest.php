<?php
namespace Tests;
use App\RabbitMQ_Consumer;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Message\AMQPMessage;
use PDO;

class RabbitMQ_ConsumerTest extends TestCase
{
    private $pdo;
    private $consumer;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE wp_users (
            ID INTEGER PRIMARY KEY AUTOINCREMENT,
            user_login TEXT,
            user_pass TEXT,
            user_email TEXT,
            user_registered TEXT,
            display_name TEXT,
            user_activation_key TEXT
        )");

        $this->pdo->exec("CREATE TABLE wp_usermeta (
            umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            meta_key TEXT,
            meta_value TEXT
        )");

        $mockChannel = $this->getMockBuilder(\PhpAmqpLib\Channel\AMQPChannel::class)
                             ->disableOriginalConstructor()
                             ->getMock();

        $this->consumer = new RabbitMQ_Consumer($this->pdo, $mockChannel);
    }

    public function testCreateUser(): void
    {
        $xml = <<<XML
<attendify>
  <info><operation>create</operation><sender>crm</sender></info>
  <user>
    <uid>user123</uid>
    <email>test@example.com</email>
    <first_name>Test</first_name>
    <last_name>User</last_name>
    <password>secret</password>
  </user>
</attendify>
XML;

        $msg = new class($xml) extends AMQPMessage {
            public function __construct($body) { parent::__construct($body); }
            public function ack($multiple = false): void {}
        };

        $this->consumer->handleMessage($msg, 'crm');

        $row = $this->pdo->query("SELECT * FROM wp_users WHERE user_email = 'test@example.com'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotEmpty($row);

        $meta = $this->pdo->query("SELECT * FROM wp_usermeta WHERE user_id = {$row['ID']} AND meta_key = 'uid'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('user123', $meta['meta_value']);
    }

    public function testUpdateUser(): void
    {
        $this->pdo->exec("INSERT INTO wp_users (ID, user_login, user_pass, user_email, user_registered, display_name)
                          VALUES (1, 'test', 'secret', 'old@example.com', '2024-01-01', 'Old User')");
        $this->pdo->exec("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (1, 'uid', 'user456')");

        $xml = <<<XML
<attendify>
  <info><operation>update</operation><sender>external</sender></info>
  <user>
    <uid>user456</uid>
    <email>new@example.com</email>
    <first_name>New</first_name>
    <last_name>Name</last_name>
  </user>
</attendify>
XML;

        $msg = new class($xml) extends AMQPMessage {
            public function __construct($body) { parent::__construct($body); }
            public function ack($multiple = false): void {}
        };

        $this->consumer->handleMessage($msg, 'external');

        $updated = $this->pdo->query("SELECT * FROM wp_users WHERE ID = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('new@example.com', $updated['user_email']);

        $meta = $this->pdo->query("SELECT * FROM wp_usermeta WHERE user_id = 1 AND meta_key = 'first_name'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('New', $meta['meta_value']);
    }

    public function testDeleteUser(): void
    {
        $this->pdo->exec("INSERT INTO wp_users (ID, user_login, user_pass, user_email, user_registered, display_name)
                          VALUES (2, 'todelete', 'secret', 'delete@example.com', '2024-01-01', 'To Delete')");
        $this->pdo->exec("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (2, 'uid', 'user789')");
        $this->pdo->exec("INSERT INTO wp_usermeta (user_id, meta_key, meta_value) VALUES (2, 'first_name', 'Bye')");

        $xml = <<<XML
<attendify>
  <info><operation>delete</operation><sender>external</sender></info>
  <user>
    <uid>user789</uid>
  </user>
</attendify>
XML;

        $msg = new class($xml) extends AMQPMessage {
            public function __construct($body) { parent::__construct($body); }
            public function ack($multiple = false): void {}
        };

        $this->consumer->handleMessage($msg, 'external');

        $count = $this->pdo->query("SELECT COUNT(*) FROM wp_users WHERE ID = 2")->fetchColumn();
        $this->assertEquals(0, $count);

        $metaCount = $this->pdo->query("SELECT COUNT(*) FROM wp_usermeta WHERE user_id = 2")->fetchColumn();
        $this->assertEquals(0, $metaCount);
    }
}
