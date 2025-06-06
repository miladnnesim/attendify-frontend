<?php
namespace Tests;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;
use App\RegistrationMessageProducer;
use Exception;
use PDO;
use InvalidArgumentException;

class RegistrationMessageProducerTest extends TestCase {
    public function testSendRegistrationMessageEvent() {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    return $msg instanceof AMQPMessage &&
                        strpos($msg->getBody(), '<event_attendee>') !== false;
                }),
                'event',
                'event.register'
            );

        $producer = new RegistrationMessageProducer($mockChannel);
        $producer->sendRegistrationMessage('event', 'user123', 'event456', 'register');
    }

    public function testSendRegistrationMessageSession() {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function ($msg) {
                    return $msg instanceof AMQPMessage &&
                        strpos($msg->getBody(), '<session_attendee>') !== false;
                }),
                'session',
                'session.register'
            );

        $producer = new RegistrationMessageProducer($mockChannel);
        $producer->sendRegistrationMessage('session', 'user789', 'session999', 'register');
    }

    public function testSendRegistrationMessageInvalidTypeThrowsException() {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("❌ Onbekend type 'invalid'. Moet 'event' of 'session' zijn.");

        $mockChannel = $this->createMock(AMQPChannel::class);
        $producer = new RegistrationMessageProducer($mockChannel);
        $producer->sendRegistrationMessage('invalid', 'user123', 'entity456', 'register');
    }
}
