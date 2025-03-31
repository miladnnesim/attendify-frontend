<?php
require_once __DIR__.'/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// RabbitMQ configuratie
$connection = new AMQPStreamConnection(
    'rabbitmq',     // host
    5672,           // port
    'attendify',    // username
    'uXe5u1oWkh32JyLA', // password
    'attendify'     // vhost
);

$channel = $connection->channel();

// Exchange declareren (moet overeenkomen met je consumer)
$exchange = 'user-management';
#$channel->exchange_declare($exchange, 'direct', false, true, false);

// Hardcoded XML bericht
$xml = '<?xml version="1.0"?>
<attendify>
  <info>
    <sender>billing</sender>
    <operation>register</operation>
  </info>
  <user>
    <id>50</id>
    <first_name>dsdssdsd</first_name>
    <last_name>Frontend</last_name>
    <date_of_birth/>
    <phone_number>123</phone_number>
    <title>Sir</title>
    <email>girl@gmail.com</email>
    <password>Admin1234</password>
    <address>
      <street/>
      <number/>
      <bus_number/>
      <city/>
      <province/>
      <country/>
      <postal_code/>
    </address>
    <payment_details>
      <facturation_address>
        <street/>
        <number/>
        <company_bus_number/>
        <city/>
        <province/>
        <country/>
        <postal_code/>
      </facturation_address>
      <payment_method/>
      <card_number/>
    </payment_details>
    <email_registered>true</email_registered>
    <company>
      <id/>
      <name/>
      <VAT_number/>
      <address>
        <street/>
        <number/>
        <city/>
        <province/>
        <country/>
        <postal_code/>
      </address>
    </company>
    <from_company>false</from_company>
  </user>
</attendify>';

// Bericht aanmaken en verzenden
$msg = new AMQPMessage(
    $xml,
    [
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'content_type' => 'application/xml'
    ]
);

$channel->basic_publish($msg, $exchange, 'user.update');
echo " [x] Verzonden update bericht voor gebruiker ID 3\n";

// Verbinding sluiten
$channel->close();
$connection->close();