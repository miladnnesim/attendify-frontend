<?php
namespace Tests;

use App\UserCompanyLinkProducer;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

class UserCompanyLinkProducerTest extends TestCase
{
    public function testSendRegisterPublishesCorrectMessage(): void
    {
        $mockChannel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);

        $mockChannel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function (AMQPMessage $msg) {
                    $xml = simplexml_load_string($msg->getBody());
                    return
                        (string)$xml->info->operation === 'register' &&
                        (string)$xml->info->sender === 'frontend' &&
                        (string)$xml->company_employee->uid === 'u1' &&
                        (string)$xml->company_employee->company_id === 'c1';
                }),
                'company',
                'company.register'
            );

        $producer = new UserCompanyLinkProducer($mockChannel);
        $producer->send('u1', 'c1', 'register');
    }

    public function testSendUnregisterPublishesCorrectMessage(): void
    {
        $mockChannel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);

        $mockChannel->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function (AMQPMessage $msg) {
                    $xml = simplexml_load_string($msg->getBody());
                    return
                        (string)$xml->info->operation === 'unregister' &&
                        (string)$xml->info->sender === 'frontend' &&
                        (string)$xml->company_employee->uid === 'u99' &&
                        (string)$xml->company_employee->company_id === 'c42';
                }),
                'company',
                'company.unregister'
            );

        $producer = new UserCompanyLinkProducer($mockChannel);
        $producer->send('u99', 'c42', 'unregister');
    }

    public function testSendWithInvalidOperationThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ongeldige operatie: invalid_op');

        $mockChannel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $producer = new UserCompanyLinkProducer($mockChannel);
        $producer->send('uid', 'cid', 'invalid_op');
    }
}
