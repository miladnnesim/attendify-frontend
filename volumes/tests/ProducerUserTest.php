<?php
namespace Tests;

// Laad Composer-autoload (bootstrap laadt 'm alvast, dus dit is een no-op)
use PHPUnit\Framework\TestCase;
use App\ProducerUser;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;

use PDO;
use PDOStatement;
use Exception;
use DateTime;
use SimpleXMLElement;
use DOMDocument;
class ProducerUserTest extends TestCase
{
    public function testBuildUserXmlProducesExpectedStructure(): void
    {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $producer    = new ProducerUser($mockChannel); 

        $user = (object)[
            'user_email' => 'foo@bar.com',
            'user_pass'  => 'secret'
        ];
        $data = [
            'first_name'   => 'John',
            'last_name'    => 'Doe',
            'birth_date'   => '1990-01-01',
            'phone_number' => '12345',
            'title'        => 'Mr',
            'street'       => 'Main St',
            'bus_number'   => '10',
            'city'         => 'Cityville',
            'province'     => 'Province',
            'country'      => 'Countryland',
            'company_uid'  => 'VAT123',
            'password'     => 'secret'
        ];

        $xml = $producer->buildUserXml('UID123', $user, $data, 'create');

        $this->assertStringContainsString('<sender>frontend</sender>',   $xml);
        $this->assertStringContainsString('<operation>create</operation>',$xml);
        $this->assertStringContainsString('<uid>UID123</uid>',           $xml);
        $this->assertStringContainsString('<first_name>John</first_name>',$xml);
        $this->assertStringContainsString('<email>foo@bar.com</email>',  $xml);
        $this->assertStringContainsString('<VAT_number>VAT123</VAT_number>',$xml);
    }

    public function testSendUserDataPublishesCreateMessageOnce(): void
    {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(fn($msg) => $msg instanceof AMQPMessage
                    && strpos($msg->getBody(), '<operation>create</operation>') !== false
                ),
                'user-management',
                'user.register'
            );

        $producer = new ProducerUser($mockChannel);
        $producer->sendUserData(1, 'create');
    }

    public function testSendUserDataSkipsDuplicateMessage(): void
    {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel->expects($this->never())->method('basic_publish');

        $producer = new ProducerUser($mockChannel);
        $xml      = $producer->buildUserXml('WP'.time(), get_userdata(1), [], 'update');
        $hash     = md5($xml);
        $GLOBALS['transient_return'] = $hash;

        $producer->sendUserData(1, 'update');
        $this->assertArrayHasKey('last_set_transient', $GLOBALS);
    }

    /**
     * @dataProvider functionProvider
     */
    public function testSendUserDataRoutingKeys($operation, $expectedRoutingKey): void
    {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel->expects($this->once())
            ->method('basic_publish')
            ->with($this->anything(), 'user-management', $expectedRoutingKey);

        (new ProducerUser($mockChannel))->sendUserData(2, $operation);
    }

    public function functionProvider(): array
    {
        return [
            ['create', 'user.register'],
            ['update', 'user.update'],
            ['delete', 'user.delete'],
        ];
    }

    public function testSendUserDataInvalidOperationThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Invalid operation: foo");

        (new ProducerUser($this->createMock(AMQPChannel::class)))->sendUserData(1, 'foo');
    }

    public function testSendUserDataUserNotFoundThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("User with ID 999 not found.");

        (new ProducerUser($this->createMock(AMQPChannel::class)))->sendUserData(999, 'create');
    }
}
