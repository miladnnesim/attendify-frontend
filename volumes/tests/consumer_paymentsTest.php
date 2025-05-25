<?php
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Message\AMQPMessage;
use App\InvoiceConsumer;

class InvoiceConsumerTest extends TestCase {
    private $pdo;
    private $consumer;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec("CREATE TABLE event_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT NOT NULL,
            event_id TEXT NOT NULL,
            entrance_fee REAL NOT NULL,
            entrance_paid INTEGER NOT NULL,
            paid_at TEXT NOT NULL,
            UNIQUE(uid, event_id)
        )");

        $this->pdo->exec("CREATE TABLE tab_sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uid TEXT NOT NULL,
            event_id TEXT NOT NULL,
            timestamp TEXT NOT NULL,
            is_paid INTEGER NOT NULL,
            UNIQUE(uid, event_id)
        )");

        $this->pdo->exec("CREATE TABLE tab_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tab_id INTEGER NOT NULL,
            item_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            price REAL NOT NULL,
            FOREIGN KEY(tab_id) REFERENCES tab_sales(id) ON DELETE CASCADE
        )");

        $this->consumer = new InvoiceConsumer($this->pdo, $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class));
    }

    public function testHandleEventPaymentCreate(): void {
        $xml = <<<XML
<attendify>
  <info>
    <operation>create_event_payment</operation>
    <sender>external</sender>
  </info>
  <event_payment>
    <uid>user123</uid>
    <event_id>ev42</event_id>
    <entrance_fee>12.5</entrance_fee>
    <entrance_paid>true</entrance_paid>
    <paid_at>2025-05-25 15:00:00</paid_at>
  </event_payment>
</attendify>
XML;
        $msg = new AMQPMessage($xml);
        $this->consumer->handleMessage($msg);

        $stmt = $this->pdo->query("SELECT * FROM event_payments WHERE uid = 'user123' AND event_id = 'ev42'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotEmpty($row);
        $this->assertEquals(12.5, $row['entrance_fee']);
        $this->assertEquals(1, $row['entrance_paid']);
        $this->assertEquals('2025-05-25 15:00:00', $row['paid_at']);
    }

public function testHandleEventPaymentUpdate(): void {
    $this->pdo->exec("INSERT INTO event_payments (uid, event_id, entrance_fee, entrance_paid, paid_at)
                      VALUES ('user123', 'ev42', 10.0, 0, '2025-01-01 10:00:00')");

    $xml = <<<XML
<attendify>
  <info>
    <operation>update_event_payment</operation>
    <sender>external</sender>
  </info>
  <event_payment>
    <uid>user123</uid>
    <event_id>ev42</event_id>
    <entrance_fee>15.5</entrance_fee>
    <entrance_paid>true</entrance_paid>
    <paid_at>2025-06-01 12:00:00</paid_at>
  </event_payment>
</attendify>
XML;

    $msg = new AMQPMessage($xml);
    $this->consumer->handleMessage($msg);

    $stmt = $this->pdo->query("SELECT * FROM event_payments WHERE uid = 'user123' AND event_id = 'ev42'");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $this->assertEquals(15.5, $row['entrance_fee']);
    $this->assertEquals(1, $row['entrance_paid']);
    $this->assertEquals('2025-06-01 12:00:00', $row['paid_at']);
}
public function testHandleEventPaymentDelete(): void {
    $this->pdo->exec("INSERT INTO event_payments (uid, event_id, entrance_fee, entrance_paid, paid_at)
                      VALUES ('user123', 'ev42', 10.0, 1, '2025-01-01 10:00:00')");

    $xml = <<<XML
<attendify>
  <info>
    <operation>delete_event_payment</operation>
    <sender>external</sender>
  </info>
  <event_payment>
    <uid>user123</uid>
    <event_id>ev42</event_id>
  </event_payment>
</attendify>
XML;

    $msg = new AMQPMessage($xml);
    $this->consumer->handleMessage($msg);

    $stmt = $this->pdo->query("SELECT COUNT(*) FROM event_payments WHERE uid = 'user123' AND event_id = 'ev42'");
    $this->assertEquals(0, $stmt->fetchColumn());
}
public function testHandleTabCreate(): void {
    $xml = <<<XML
<attendify>
  <info>
    <operation>create</operation>
    <sender>external</sender>
  </info>
  <tab>
    <uid>u1</uid>
    <event_id>e99</event_id>
    <timestamp>2025-05-25 14:00:00</timestamp>
    <is_paid>true</is_paid>
    <items>
      <tab_item>
        <item_name>Cola</item_name>
        <quantity>2</quantity>
        <price>3.5</price>
      </tab_item>
    </items>
  </tab>
</attendify>
XML;

    $msg = new AMQPMessage($xml);
    $this->consumer->handleMessage($msg);

    $sale = $this->pdo->query("SELECT * FROM tab_sales WHERE uid = 'u1' AND event_id = 'e99'")->fetch(PDO::FETCH_ASSOC);
    $this->assertNotEmpty($sale);
    $this->assertEquals(1, $sale['is_paid']);

    $items = $this->pdo->query("SELECT * FROM tab_items WHERE tab_id = {$sale['id']}")->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $items);
    $this->assertEquals('Cola', $items[0]['item_name']);
}
public function testHandleTabUpdate(): void {
    // Eerst tab + item aanmaken
    $this->pdo->exec("INSERT INTO tab_sales (id, uid, event_id, timestamp, is_paid) VALUES (1, 'u1', 'e99', '2025-01-01 10:00:00', 0)");
    $this->pdo->exec("INSERT INTO tab_items (tab_id, item_name, quantity, price) VALUES (1, 'Cola', 1, 3.0)");

    $xml = <<<XML
<attendify>
  <info>
    <operation>update</operation>
    <sender>external</sender>
  </info>
  <tab>
    <uid>u1</uid>
    <event_id>e99</event_id>
    <timestamp>2025-05-25 18:00:00</timestamp>
    <is_paid>true</is_paid>
    <items>
      <tab_item>
        <item_name>Fanta</item_name>
        <quantity>3</quantity>
        <price>4.0</price>
      </tab_item>
    </items>
  </tab>
</attendify>
XML;

    $msg = new AMQPMessage($xml);
    $this->consumer->handleMessage($msg);

    $sale = $this->pdo->query("SELECT * FROM tab_sales WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    $this->assertEquals(1, $sale['is_paid']);
    $this->assertEquals('2025-05-25 18:00:00', $sale['timestamp']);

    $items = $this->pdo->query("SELECT * FROM tab_items WHERE tab_id = 1")->fetchAll(PDO::FETCH_ASSOC);
    $this->assertCount(1, $items);
    $this->assertEquals('Fanta', $items[0]['item_name']);
}
public function testHandleTabDelete(): void {
    $this->pdo->exec("INSERT INTO tab_sales (id, uid, event_id, timestamp, is_paid) VALUES (1, 'u1', 'e99', '2025-01-01 10:00:00', 0)");
    $this->pdo->exec("INSERT INTO tab_items (tab_id, item_name, quantity, price) VALUES (1, 'Cola', 1, 3.0)");

    $xml = <<<XML
<attendify>
  <info>
    <operation>delete</operation>
    <sender>external</sender>
  </info>
  <tab>
    <uid>u1</uid>
    <event_id>e99</event_id>
  </tab>
</attendify>
XML;

    $msg = new AMQPMessage($xml);
    $this->consumer->handleMessage($msg);

    $count = $this->pdo->query("SELECT COUNT(*) FROM tab_sales WHERE id = 1")->fetchColumn();
    $this->assertEquals(0, $count);

    $items = $this->pdo->query("SELECT COUNT(*) FROM tab_items WHERE tab_id = 1")->fetchColumn();
    $this->assertEquals(0, $items);
}
}