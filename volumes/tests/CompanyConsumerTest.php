<?php
// tests/CompanyConsumerTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\CompanyConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PDO;
use Exception;

class CompanyConsumerTest extends TestCase
{
    private PDO $db;
    private CompanyConsumer $consumer;

    protected function setUp(): void
    {
        // 1) In‐memory SQLite als stand-in voor MySQL
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 2) Simuleer de basis tabellen
        //   a) companies zonder kolommen (initTables voegt ze toe)
        $this->db->exec("
            CREATE TABLE companies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT,
                updated_at TEXT
            )
        ");
        //   b) wp_usermeta voor employee‐links
        $this->db->exec("
            CREATE TABLE wp_usermeta (
                umeta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                meta_key TEXT,
                meta_value TEXT
            )
        ");

        // 3) Gemockte Rabbit-channel
        $mockChannel = $this->createMock(AMQPChannel::class);

        // 4) Consumer maken en tabellen initialiseren
        $this->consumer = new CompanyConsumer($this->db, $mockChannel);
        $this->consumer->initTables();
    }

    public function testHandleCompanyCreate(): void
    {
        $xml = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>create</operation></info>
  <companies>
    <company>
      <uid>C1</uid>
      <companyNumber>CN1</companyNumber>
      <name>Name1</name>
      <VATNumber>VAT1</VATNumber>
      <address>
        <street>St</street><number>1</number><postcode>1000</postcode><city>City</city>
      </address>
      <billingAddress>
        <street>BSt</street><number>2</number><postcode>2000</postcode><city>BCity</city>
      </billingAddress>
      <email>e@e</email>
      <phone>p</phone>
      <owner_id>O1</owner_id>
    </company>
  </companies>
</attendify>
XML;
        $msg = $this->makeMockedMsg($xml);
        $this->consumer->handleMessage($msg);

        $row = $this->db
            ->query("SELECT * FROM companies WHERE uid = 'C1'")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('CN1', $row['companyNumber']);
        $this->assertSame('Name1', $row['name']);
        $this->assertSame('VAT1', $row['VATNumber']);
        $this->assertSame('O1', $row['owner_id']);
    }

    public function testHandleCompanyUpdate(): void
    {
        // eerst aanmaken
        $this->db->exec("
            INSERT INTO companies (uid, companyNumber, name, VATNumber)
            VALUES ('C2','OLD','OldName','OLDVAT')
        ");

        $xml = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>update</operation></info>
  <companies>
    <company>
      <uid>C2</uid>
      <companyNumber>NEWCN</companyNumber>
      <name>NewName</name>
      <VATNumber>NEWVAT</VATNumber>
      <address>
        <street>St2</street><number>9</number><postcode>9000</postcode><city>NewCity</city>
      </address>
      <billingAddress>
        <street>BST2</street><number>10</number><postcode>10000</postcode><city>BCity2</city>
      </billingAddress>
      <email>new@e</email>
      <phone>newp</phone>
      <owner_id>O2</owner_id>
    </company>
  </companies>
</attendify>
XML;
        $msg = $this->makeMockedMsg($xml);
        $this->consumer->handleMessage($msg);

        $row = $this->db
            ->query("SELECT * FROM companies WHERE uid = 'C2'")
            ->fetch(PDO::FETCH_ASSOC);

        $this->assertSame('NEWCN', $row['companyNumber']);
        $this->assertSame('NewName', $row['name']);
        $this->assertSame('NEWVAT', $row['VATNumber']);
        $this->assertSame('O2', $row['owner_id']);
    }

    public function testHandleCompanyDelete(): void
    {
        // aanmaken
        $this->db->exec("
            INSERT INTO companies (uid) VALUES ('C3')
        ");

        $xml = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>delete</operation></info>
  <companies>
    <company><uid>C3</uid></company>
  </companies>
</attendify>
XML;
        $msg = $this->makeMockedMsg($xml);
        $this->consumer->handleMessage($msg);

        $count = $this->db
            ->query("SELECT COUNT(*) FROM companies WHERE uid = 'C3'")
            ->fetchColumn();

        $this->assertSame(0, (int)$count);
    }

    public function testHandleCompanyCreateExistingThrows(): void
    {
        $this->db->exec("INSERT INTO companies (uid) VALUES ('C4')");

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("❌ Bedrijf C4 bestaat al");

        $xml = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>create</operation></info>
  <companies><company><uid>C4</uid></company></companies>
</attendify>
XML;
        $msg = $this->makeMockedMsg($xml);
        $this->consumer->handleMessage($msg);
    }

    public function testHandleCompanyUpdateMissingThrows(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("❌ Bedrijf C5 bestaat niet");

        $xml = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>update</operation></info>
  <companies><company><uid>C5</uid></company></companies>
</attendify>
XML;
        $msg = $this->makeMockedMsg($xml);
        $this->consumer->handleMessage($msg);
    }

    public function testHandleCompanyEmployeeRegisterAndUnregister(): void
    {
        // Simuleer bestaande wp_usermeta met key 'uid'
        $this->db->exec("
            INSERT INTO wp_usermeta (user_id, meta_key, meta_value)
            VALUES (42, 'uid', 'USERX')
        ");

        // eerst register
        $xmlReg = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>register</operation></info>
  <company_employee>
    <uid>USERX</uid>
    <company_id>C6</company_id>
  </company_employee>
</attendify>
XML;
        $msgReg = $this->makeMockedMsg($xmlReg);
        $this->consumer->handleMessage($msgReg);

        // check upsert: beide meta_keys bestaan nu
        $rows = $this->db->query("SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = 42")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('C6', $rows['company_vat_number']);
        $this->assertSame('C6', $rows['old_company_vat_number']);

        // dan unregister → company_vat_number reset naar empty
        $xmlUn = <<<XML
<attendify>
  <info><sender>frontend</sender><operation>unregister</operation></info>
  <company_employee>
    <uid>USERX</uid>
    <company_id>C6</company_id>
  </company_employee>
</attendify>
XML;
        $msgUn = $this->makeMockedMsg($xmlUn);
        $this->consumer->handleMessage($msgUn);

        $rows2 = $this->db->query("SELECT meta_key, meta_value FROM wp_usermeta WHERE user_id = 42")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame('', $rows2['company_vat_number']);
        $this->assertSame('', $rows2['old_company_vat_number']);
    }

    private function makeMockedMsg(string $body): AMQPMessage
    {
        $mockMsg = $this->getMockBuilder(AMQPMessage::class)
            ->setConstructorArgs([$body])
            ->onlyMethods(['ack'])
            ->getMock();
        $mockMsg->expects($this->once())->method('ack');
        return $mockMsg;
    }

}
