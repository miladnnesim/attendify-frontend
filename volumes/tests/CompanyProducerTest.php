<?php
// tests/CompanyProducerTest.php

namespace Tests;

use PHPUnit\Framework\TestCase;
use App\CompanyProducer;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Channel\AMQPChannel;

class CompanyProducerTest extends TestCase
{
    public function testSendCompanyDataPublishesCreateMessage(): void
    {
        $data = [
            'uid'            => 'C123',
            'companyNumber'  => '12345678',
            'name'           => 'Acme Corp',
            'VATNumber'      => 'BE0123456789',
            'street'         => 'Main St',
            'number'         => '1',
            'postcode'       => '1000',
            'city'           => 'Brussels',
            'billing_street' => 'Billing St',
            'billing_number' => '2',
            'billing_postcode' => '2000',
            'billing_city'   => 'Antwerp',
            'email'          => 'info@acme.example',
            'phone'          => '+3212345678',
            'owner_id'       => 'U999'
        ];

        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function(AMQPMessage $msg) use ($data) {
                    $xml = $msg->getBody();
                    // operation and sender
                    return strpos($xml, '<operation>create</operation>') !== false
                        && strpos($xml, '<sender>frontend</sender>') !== false
                        // company fields
                        && strpos($xml, "<uid>{$data['uid']}</uid>") !== false
                        && strpos($xml, "<companyNumber>{$data['companyNumber']}</companyNumber>") !== false
                        && strpos($xml, "<name>{$data['name']}</name>") !== false
                        && strpos($xml, "<VATNumber>{$data['VATNumber']}</VATNumber>") !== false
                        && strpos($xml, "<street>{$data['street']}</street>") !== false
                        && strpos($xml, "<owner_id>{$data['owner_id']}</owner_id>") !== false;
                }),
                'company',
                'company.create'
            );

        $producer = new CompanyProducer($mockChannel);
        $producer->sendCompanyData($data, 'create');
    }

    /**
     * @dataProvider operationProvider
     */
    public function testSendCompanyDataRoutingKeysAndMinimalPayload(string $operation, array $data, array $mustContain): void
    {
        $mockChannel = $this->createMock(AMQPChannel::class);
        $mockChannel
            ->expects($this->once())
            ->method('basic_publish')
            ->with(
                $this->callback(function(AMQPMessage $msg) use ($operation, $mustContain) {
                    $xml = $msg->getBody();
                    // check operation tag
                    if (strpos($xml, "<operation>{$operation}</operation>") === false) {
                        return false;
                    }
                    // each mustContain string must appear
                    foreach ($mustContain as $fragment) {
                        if (strpos($xml, $fragment) === false) {
                            return false;
                        }
                    }
                    return true;
                }),
                'company',
                "company.{$operation}"
            );

        $producer = new CompanyProducer($mockChannel);
        $producer->sendCompanyData($data, $operation);
    }

    public function operationProvider(): array
    {
        return [
            'update minimal' => [
                'update',
                // for update we supply only uid
                ['uid' => 'C456'],
                [
                    '<uid>C456</uid>',
                    // other fields should be absent in the minimal update payload,
                    // but at least presence of address elements
                    '<companyNumber>',
                ]
            ],
            'delete minimal' => [
                'delete',
                ['uid' => 'C789'],
                [
                    '<uid>C789</uid>',
                    // no extra fields at all
                    '<companyNumber>' // buildXML still adds the node but empty
                ]
            ],
        ];
    }
}
