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
                ondernemingsnummer VARCHAR(20) UNIQUE,
                naam VARCHAR(255),
                btwnummer VARCHAR(20),
                straat VARCHAR(255),
                nummer VARCHAR(10),
                postcode VARCHAR(10),
                gemeente VARCHAR(255),
                facturatie_straat VARCHAR(255),
                facturatie_nummer VARCHAR(10),
                facturatie_postcode VARCHAR(10),
                facturatie_gemeente VARCHAR(255),
                email VARCHAR(255),
                telefoon VARCHAR(50),
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
            $bedrijf = $xml->bedrijf;
            if (!$bedrijf) throw new Exception("❌ Geen <bedrijf> gevonden in XML");

            $this->handleCompany($bedrijf, $operation);

            $msg->ack();
        } catch (Exception $e) {
            error_log("[ERROR] " . $e->getMessage());
            $msg->ack(); // Vermijd eindeloze requeue
        }
    }

    private function handleCompany(SimpleXMLElement $bedrijf, $operation) {
        $ondernemingsnummer = (string) $bedrijf->ondernemingsNummer;
        if (!$ondernemingsnummer) throw new Exception("❌ ondernemingsNummer ontbreekt");

        if ($operation === 'delete') {
            $stmt = $this->db->prepare("DELETE FROM companies WHERE ondernemingsnummer = :nr");
            $stmt->execute([':nr' => $ondernemingsnummer]);
            error_log("❌ Bedrijf $ondernemingsnummer verwijderd");
            return;
        }

        // insert or update
        $stmt = $this->db->prepare("
            INSERT INTO companies (
                ondernemingsnummer, naam, btwnummer,
                straat, nummer, postcode, gemeente,
                facturatie_straat, facturatie_nummer, facturatie_postcode, facturatie_gemeente,
                email, telefoon
            ) VALUES (
                :ondernemingsnummer, :naam, :btw,
                :straat, :nummer, :postcode, :gemeente,
                :f_straat, :f_nummer, :f_postcode, :f_gemeente,
                :email, :telefoon
            )
            ON DUPLICATE KEY UPDATE
                naam = VALUES(naam),
                btwnummer = VALUES(btwnummer),
                straat = VALUES(straat),
                nummer = VALUES(nummer),
                postcode = VALUES(postcode),
                gemeente = VALUES(gemeente),
                facturatie_straat = VALUES(facturatie_straat),
                facturatie_nummer = VALUES(facturatie_nummer),
                facturatie_postcode = VALUES(facturatie_postcode),
                facturatie_gemeente = VALUES(facturatie_gemeente),
                email = VALUES(email),
                telefoon = VALUES(telefoon)
        ");
        $stmt->execute([
            ':ondernemingsnummer' => $ondernemingsnummer,
            ':naam' => (string) $bedrijf->naam,
            ':btw' => (string) $bedrijf->btwNummer,
            ':straat' => (string) $bedrijf->adres->straat,
            ':nummer' => (string) $bedrijf->adres->nummer,
            ':postcode' => (string) $bedrijf->adres->postcode,
            ':gemeente' => (string) $bedrijf->adres->gemeente,
            ':f_straat' => (string) $bedrijf->facturatieAdres->straat,
            ':f_nummer' => (string) $bedrijf->facturatieAdres->nummer,
            ':f_postcode' => (string) $bedrijf->facturatieAdres->postcode,
            ':f_gemeente' => (string) $bedrijf->facturatieAdres->gemeente,
            ':email' => (string) $bedrijf->email,
            ':telefoon' => (string) $bedrijf->telefoon
        ]);

        error_log("✅ Bedrijf $ondernemingsnummer " . ($operation === 'create' ? 'aangemaakt' : 'bijgewerkt'));
    }
}

try {
    new CompanyConsumer();
} catch (Exception $e) {
    error_log("❌ CompanyConsumer kon niet starten: " . $e->getMessage());
    exit(1);
}
