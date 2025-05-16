<?php
require_once '/var/www/html/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class CompanyConsumer {
    private $connection;
    private $channel;
    private $db;
    private $queue = 'frontend.company';

    public function __construct() {
        $this->connectToDB();
        $this->initTable();
        $this->connectToRabbitMQ();
        $this->consume();
    }

    private function connectToDB() {
        $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
        $this->db = new PDO($dsn, getenv('LOCAL_DB_USER') ?: 'root', getenv('LOCAL_DB_PASSWORD') ?: 'root');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        error_log("✅ Verbonden met database");
    }

    private function initTable() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS companies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uid VARCHAR(30) UNIQUE,
                companyNumber VARCHAR(20),
                name VARCHAR(255),
                VATNumber VARCHAR(20),
                street VARCHAR(255),
                number VARCHAR(10),
                postcode VARCHAR(10),
                city VARCHAR(255),
                billing_street VARCHAR(255),
                billing_number VARCHAR(10),
                billing_postcode VARCHAR(10),
                billing_city VARCHAR(255),
                email VARCHAR(255),
                phone VARCHAR(50),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function connectToRabbitMQ() {
        $this->connection = new AMQPStreamConnection(
            'rabbitmq',
            getenv('RABBITMQ_AMQP_PORT') ?: 5672,
            getenv('RABBITMQ_HOST') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_USER') ?: 'guest'
        );
        $this->channel = $this->connection->channel();
        $this->channel->basic_qos(null, 1, null);
        error_log("✅ Verbonden met RabbitMQ");
    }

    private function consume() {
        $this->channel->basic_consume($this->queue, '', false, false, false, false, [$this, 'handleMessage']);

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function handleMessage(AMQPMessage $msg) {
        try {
            $xml = simplexml_load_string($msg->body);
            if (!$xml) throw new Exception("❌ Ongeldig XML-formaat");

            $operation = (string) $xml->info->operation;
            $company = $xml->companies->company;
            if (!$company) throw new Exception("❌ Geen <company> gevonden in XML");

            $this->handleCompany($company, $operation);

            $msg->ack();
        } catch (Exception $e) {
            error_log("[ERROR] " . $e->getMessage());
            $msg->ack(); // Vermijd eindeloze requeue
        }
    }

    private function handleCompany(SimpleXMLElement $company, $operation) {
        $uid = (string) $company->uid;
        if (!$uid) throw new Exception("❌ uid ontbreekt");

        if ($operation === 'delete') {
            $stmt = $this->db->prepare("DELETE FROM companies WHERE uid = :uid");
            $stmt->execute([':uid' => $uid]);
            error_log("❌ Bedrijf $uid verwijderd");
            return;
        }

        // insert or update
        $stmt = $this->db->prepare("
            INSERT INTO companies (
                uid, companyNumber, name, VATNumber,
                street, number, postcode, city,
                billing_street, billing_number, billing_postcode, billing_city,
                email, phone
            ) VALUES (
                :uid, :companyNumber, :name, :VATNumber,
                :street, :number, :postcode, :city,
                :billing_street, :billing_number, :billing_postcode, :billing_city,
                :email, :phone
            )
            ON DUPLICATE KEY UPDATE
                companyNumber = VALUES(companyNumber),
                name = VALUES(name),
                VATNumber = VALUES(VATNumber),
                street = VALUES(street),
                number = VALUES(number),
                postcode = VALUES(postcode),
                city = VALUES(city),
                billing_street = VALUES(billing_street),
                billing_number = VALUES(billing_number),
                billing_postcode = VALUES(billing_postcode),
                billing_city = VALUES(billing_city),
                email = VALUES(email),
                phone = VALUES(phone)
        ");

        $stmt->execute([
            ':uid' => $uid,
            ':companyNumber' => (string) $company->companyNumber,
            ':name' => (string) $company->name,
            ':VATNumber' => (string) $company->VATNumber,
            ':street' => (string) $company->address->street,
            ':number' => (string) $company->address->number,
            ':postcode' => (string) $company->address->postcode,
            ':city' => (string) $company->address->city,
            ':billing_street' => (string) $company->billingAddress->street,
            ':billing_number' => (string) $company->billingAddress->number,
            ':billing_postcode' => (string) $company->billingAddress->postcode,
            ':billing_city' => (string) $company->billingAddress->city,
            ':email' => (string) $company->email,
            ':phone' => (string) $company->phone
        ]);

        error_log("✅ Bedrijf $uid " . ($operation === 'register' ? 'aangemaakt' : 'bijgewerkt'));
    }
}

try {
    new CompanyConsumer();
} catch (Exception $e) {
    error_log("❌ CompanyConsumer kon niet starten: " . $e->getMessage());
    exit(1);
} ?>