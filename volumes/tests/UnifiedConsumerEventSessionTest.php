<?php
namespace Tests;

use PHPUnit\Framework\TestCase;
use App\UnifiedConsumerEventSession;
use PhpAmqpLib\Channel\AMQPChannel;
use PDO;
use PDOStatement;
use ReflectionClass;

class UnifiedConsumerEventSessionTest extends TestCase
{
    private $consumer;
    private $mockPdo;

    protected function setUp(): void
    {
        // 1️⃣ Maak een PDO-mock (zonder constructor, dus geen echte DB-connectie)
        $this->mockPdo = $this->getMockBuilder(PDO::class)
                              ->disableOriginalConstructor()
                              ->getMock();

        // 2️⃣ Maak een AMQPChannel-mock (we testen hier niet de consume-loop)
        $mockChannel = $this->createMock(AMQPChannel::class);

        // 3️⃣ Instantiate de consumer zonder constructor
        $ref = new ReflectionClass(UnifiedConsumerEventSession::class);
        $this->consumer = $ref->newInstanceWithoutConstructor();

        // 4️⃣ Inject de mocks in de private properties
        $pDb = $ref->getProperty('db');
        $pDb->setAccessible(true);
        $pDb->setValue($this->consumer, $this->mockPdo);

        $pCh = $ref->getProperty('channel');
        $pCh->setAccessible(true);
        $pCh->setValue($this->consumer, $mockChannel);
    }

    public function testProcessRegistrationRegisterPreparesAndExecutesInsertIgnore(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);

        // Verwacht dat de juiste MySQL-query wordt ge-prepare’d
        $this->mockPdo
             ->expects($this->once())
             ->method('prepare')
             ->with("INSERT IGNORE INTO `user_event` (user_id, `event_id`) VALUES (:uid, :eid)")
             ->willReturn($mockStmt);

        // En dat execute met de juiste parameters wordt aangeroepen
        $mockStmt
             ->expects($this->once())
             ->method('execute')
             ->with([':uid' => 'user1', ':eid' => 'event1']);

        // Roep private processRegistration aan
        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('processRegistration');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, ['event', 'register', 'user1', 'event1']);
    }

    public function testProcessRegistrationUnregisterPreparesAndExecutesDelete(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);

        $this->mockPdo
             ->expects($this->once())
             ->method('prepare')
             ->with("DELETE FROM `user_event` WHERE user_id = :uid AND `event_id` = :eid")
             ->willReturn($mockStmt);

        $mockStmt
             ->expects($this->once())
             ->method('execute')
             ->with([':uid' => 'user2', ':eid' => 'event2']);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('processRegistration');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, ['event', 'unregister', 'user2', 'event2']);
    }

    public function testProcessRegistrationUnknownOperationThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Unknown operation: foo");

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('processRegistration');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, ['session', 'foo', 'u', 's']);
    }

    public function testHandleEventCreateAndUpdatePrepareAndExecutesUpsert(): void
    {
        $xmlStr = <<<XML
<root>
  <event>
    <uid>evt1</uid>
    <title>T</title>
    <description>D</description>
    <location>L</location>
    <start_date>2025-05-25</start_date>
    <end_date>2025-05-26</end_date>
    <start_time>10:00:00</start_time>
    <end_time>12:00:00</end_time>
    <organizer_name>O</organizer_name>
    <organizer_uid>ouid</organizer_uid>
    <entrance_fee>20.50</entrance_fee>
  </event>
</root>
XML;
        $xml = simplexml_load_string($xmlStr);

        $mockStmt = $this->createMock(PDOStatement::class);

        // Wéér: ON DUPLICATE-query (zelfde SQL voor create en update)
        $this->mockPdo
             ->expects($this->exactly(2))
             ->method('prepare')
             ->with($this->stringContains('INSERT INTO wp_events'))
             ->willReturn($mockStmt);

        $mockStmt
             ->expects($this->exactly(2))
             ->method('execute')
             ->with([
                ':uid'   => 'evt1',
                ':title' => 'T',
                ':desc'  => 'D',
                ':loc'   => 'L',
                ':sd'    => '2025-05-25',
                ':ed'    => '2025-05-26',
                ':st'    => '10:00:00',
                ':et'    => '12:00:00',
                ':oname'=> 'O',
                ':ouid' => 'ouid',
                ':fee'  => '20.50',
             ]);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('handleEvent');
        $m->setAccessible(true);

        // create
        $m->invokeArgs($this->consumer, [$xml->event, 'create']);
        // update
        $m->invokeArgs($this->consumer, [$xml->event, 'update']);
    }

    public function testHandleEventDeletePreparesAndExecutesDelete(): void
    {
        $xmlStr = "<root><event><uid>e2</uid></event></root>";
        $xml = simplexml_load_string($xmlStr);

        $mockStmt = $this->createMock(PDOStatement::class);
        $this->mockPdo
             ->expects($this->once())
             ->method('prepare')
             ->with("DELETE FROM wp_events WHERE uid = :uid")
             ->willReturn($mockStmt);

        $mockStmt
             ->expects($this->once())
             ->method('execute')
             ->with([':uid' => 'e2']);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('handleEvent');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, [$xml->event, 'delete']);
    }

    public function testHandleEventMissingUidThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Event uid missing");

        $xmlStr = "<root><event><uid></uid></event></root>";
        $xml = simplexml_load_string($xmlStr);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('handleEvent');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, [$xml->event, 'create']);
    }

    public function testHandleSessionCreateAndUpdatePrepareAndExecutesUpsert(): void
    {
        $xmlStr = <<<XML
<root>
  <session>
    <uid>s1</uid>
    <event_id>e1</event_id>
    <title>T</title>
    <description>D</description>
    <date>2025-05-25</date>
    <start_time>09:00:00</start_time>
    <end_time>11:00:00</end_time>
    <location>X</location>
    <max_attendees>100</max_attendees>
    <speaker>
      <name>S</name>
      <bio>B</bio>
    </speaker>
  </session>
</root>
XML;
        $xml = simplexml_load_string($xmlStr);

        $mockStmt = $this->createMock(PDOStatement::class);
        $this->mockPdo
             ->expects($this->exactly(2))
             ->method('prepare')
             ->with($this->stringContains('INSERT INTO wp_sessions'))
             ->willReturn($mockStmt);

        $mockStmt
             ->expects($this->exactly(2))
             ->method('execute')
             ->with([
                ':uid'   => 's1',
                ':euid' => 'e1',
                ':title'=> 'T',
                ':desc' => 'D',
                ':date' => '2025-05-25',
                ':st'   => '09:00:00',
                ':et'   => '11:00:00',
                ':loc'  => 'X',
                ':max'  => 100,
                ':sname'=> 'S',
                ':sbio' => 'B',
             ]);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('handleSession');
        $m->setAccessible(true);

        $m->invokeArgs($this->consumer, [$xml->session, 'create']);
        $m->invokeArgs($this->consumer, [$xml->session, 'update']);
    }

    public function testHandleSessionDeletePreparesAndExecutesDelete(): void
    {
        $xmlStr = "<root><session><uid>sess2</uid></session></root>";
        $xml = simplexml_load_string($xmlStr);

        $mockStmt = $this->createMock(PDOStatement::class);
        $this->mockPdo
             ->expects($this->once())
             ->method('prepare')
             ->with("DELETE FROM wp_sessions WHERE uid = :uid")
             ->willReturn($mockStmt);

        $mockStmt
             ->expects($this->once())
             ->method('execute')
             ->with([':uid' => 'sess2']);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('handleSession');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, [$xml->session, 'delete']);
    }

    public function testHandleSessionMissingUidThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Session uid missing");

        $xmlStr = "<root><session><uid></uid></session></root>";
        $xml = simplexml_load_string($xmlStr);

        $refM = new ReflectionClass($this->consumer);
        $m = $refM->getMethod('handleSession');
        $m->setAccessible(true);
        $m->invokeArgs($this->consumer, [$xml->session, 'create']);
    }
}
