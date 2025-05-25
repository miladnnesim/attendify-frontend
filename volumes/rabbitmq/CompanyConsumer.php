<?php
namespace App;
require_once __DIR__ . '/../vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PDO;
use PDOStatement;
use Exception;
use DateTime;
use SimpleXMLElement;
class CompanyConsumer {
    private $connection;
    private $channel;
    private $db;
    private $queue = 'frontend.company';
    private $table_prefix = 'wp';

    public function __construct(?PDO $db = null, ?AMQPChannel $channel = null) {
        $this->db = $db ?? $this->createDbConnection();
        $this->channel = $channel ?? $this->connectToRabbitMQ();
    }

    public function run(): void {
        $this->initTables();
        $this->consume();
    }

    private function createDbConnection(): PDO {
        $dsn = "mysql:host=db;dbname=wordpress;charset=utf8mb4";
        $pdo = new PDO($dsn, getenv('LOCAL_DB_USER') ?: 'root', getenv('LOCAL_DB_PASSWORD') ?: 'root');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        error_log("✅ Verbonden met database");
        return $pdo;
    }

    private function connectToRabbitMQ(): AMQPChannel {
        $this->connection = new AMQPStreamConnection(
            'rabbitmq',
            getenv('RABBITMQ_AMQP_PORT') ?: 5672,
            getenv('RABBITMQ_HOST') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_USER') ?: 'guest'
        );
        $channel = $this->connection->channel();
        $channel->basic_qos(null, 1, null);
        error_log("✅ Verbonden met RabbitMQ");
        return $channel;
    }

    public function initTables(): void {
            $is_sqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';

    if ($is_sqlite) {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS companies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )
        ");
    } else {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS companies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

        $columns = [
            'uid' => 'VARCHAR(30) UNIQUE',
            'companyNumber' => 'VARCHAR(20)',
            'name' => 'VARCHAR(255)',
            'VATNumber' => 'VARCHAR(20)',
            'street' => 'VARCHAR(255)',
            'number' => 'VARCHAR(10)',
            'postcode' => 'VARCHAR(10)',
            'city' => 'VARCHAR(255)',
            'billing_street' => 'VARCHAR(255)',
            'billing_number' => 'VARCHAR(10)',
            'billing_postcode' => 'VARCHAR(10)',
            'billing_city' => 'VARCHAR(255)',
            'email' => 'VARCHAR(255)',
            'phone' => 'VARCHAR(50)',
            'owner_id' => 'VARCHAR(30)'
        ];

        foreach ($columns as $col => $type) {
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // SQLite: haal kolomnamen uit PRAGMA table_info
            $colNames = array_column(
                $this->db->query("PRAGMA table_info(companies)")->fetchAll(\PDO::FETCH_ASSOC),
                'name'
            );
            if (!in_array($col, $colNames)) {
                $this->db->exec("ALTER TABLE companies ADD COLUMN $col $type");
                error_log("ℹ️ [sqlite] Kolom '$col' toegevoegd aan 'companies'");
            }
        } else {
            // MySQL
            $stmt = $this->db->query("SHOW COLUMNS FROM companies LIKE '$col'");
            if ($stmt->rowCount() === 0) {
                $this->db->exec("ALTER TABLE companies ADD COLUMN $col $type");
                error_log("ℹ️ [mysql] Kolom '$col' toegevoegd aan 'companies'");
            }
        }
    }

    }

    private function consume(): void {
        $this->channel->basic_consume($this->queue, '', false, false, false, false, [$this, 'handleMessage']);

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function handleMessage(AMQPMessage $msg): void {
        try {
            $xml = simplexml_load_string($msg->body);
            if (!$xml) throw new Exception("❌ Ongeldig XML-formaat");

            $operation = (string) $xml->info->operation;

            if (isset($xml->companies->company)) {
                $this->handleCompany($xml->companies->company, $operation);
            }

            if (isset($xml->company_employee)) {
                $this->handleCompanyEmployee($xml->company_employee, $operation);
            }

            $msg->ack();
        } catch (Exception $e) {
            error_log("[ERROR] " . $e->getMessage());
            $msg->ack(); // Geen eindeloze retry loop
        }
    }

    private function handleCompany(SimpleXMLElement $company, string $operation): void {
        $uid = (string) $company->uid;
        if (!$uid) throw new Exception("❌ uid ontbreekt bij company");

        $owner_id = (string) $company->owner_id;

        $check = $this->db->prepare("SELECT COUNT(*) FROM companies WHERE uid = :uid");
        $check->execute([':uid' => $uid]);
        $exists = $check->fetchColumn() > 0;

        if ($operation === 'create') {
            if ($exists) throw new Exception("❌ Bedrijf $uid bestaat al");

            $stmt = $this->db->prepare("
                INSERT INTO companies (
                    uid, companyNumber, name, VATNumber,
                    street, number, postcode, city,
                    billing_street, billing_number, billing_postcode, billing_city,
                    email, phone, owner_id
                ) VALUES (
                    :uid, :companyNumber, :name, :VATNumber,
                    :street, :number, :postcode, :city,
                    :billing_street, :billing_number, :billing_postcode, :billing_city,
                    :email, :phone, :owner_id
                )
            ");
        } elseif ($operation === 'update') {
            if (!$exists) throw new Exception("❌ Bedrijf $uid bestaat niet");

            $stmt = $this->db->prepare("
                UPDATE companies SET
                    companyNumber = :companyNumber,
                    name = :name,
                    VATNumber = :VATNumber,
                    street = :street,
                    number = :number,
                    postcode = :postcode,
                    city = :city,
                    billing_street = :billing_street,
                    billing_number = :billing_number,
                    billing_postcode = :billing_postcode,
                    billing_city = :billing_city,
                    email = :email,
                    phone = :phone,
                    owner_id = :owner_id
                WHERE uid = :uid
            ");
        } elseif ($operation === 'delete') {
            $stmt = $this->db->prepare("DELETE FROM companies WHERE uid = :uid");
            $stmt->execute([':uid' => $uid]);
            error_log("🗑️ Bedrijf $uid verwijderd");
            return;
        } else {
            throw new Exception("❌ Ongeldige operatie: $operation");
        }

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
            ':phone' => (string) $company->phone,
            ':owner_id' => $owner_id
        ]);
    }

    private function handleCompanyEmployee(SimpleXMLElement $employee, string $operation): void {
        $user_uid = (string) $employee->uid;
        $company_uid = (string) $employee->company_id;

        if (!$user_uid || !$company_uid) {
            throw new Exception("❌ Fout in company_employee: ontbrekende uid of company_id");
        }

        if ($operation === 'register') {
            $this->updateUserMetaCompanyLink($user_uid, $company_uid);
        } elseif ($operation === 'unregister') {
            $this->updateUserMetaCompanyLink($user_uid, '');
        } else {
            throw new Exception("❌ Ongeldige operatie voor user-company link: $operation");
        }
    }

    private function updateUserMetaCompanyLink(string $user_uid, string $company_uid): void {
        $stmt = $this->db->prepare("SELECT user_id FROM {$this->table_prefix}_usermeta WHERE meta_key = 'uid' AND meta_value = :uid LIMIT 1");
        $stmt->execute([':uid' => $user_uid]);
        $user_id = $stmt->fetchColumn();

        if (!$user_id) {
            error_log("❌ Geen gebruiker gevonden met uid: $user_uid");
            return;
        }

        $this->upsertUserMeta($user_id, 'company_vat_number', $company_uid);
        $this->upsertUserMeta($user_id, 'old_company_vat_number', $company_uid);

        error_log("🔗 Gebruiker $user_uid gelinkt aan bedrijf $company_uid");
    }

    private function upsertUserMeta(int $user_id, string $meta_key, string $meta_value): void {
        $stmt = $this->db->prepare("SELECT umeta_id FROM {$this->table_prefix}_usermeta WHERE user_id = :user_id AND meta_key = :meta_key");
        $stmt->execute([':user_id' => $user_id, ':meta_key' => $meta_key]);

        if ($stmt->fetchColumn()) {
            $update = $this->db->prepare("UPDATE {$this->table_prefix}_usermeta SET meta_value = :meta_value WHERE user_id = :user_id AND meta_key = :meta_key");
            $update->execute([':meta_value' => $meta_value, ':user_id' => $user_id, ':meta_key' => $meta_key]);
        } else {
            $insert = $this->db->prepare("INSERT INTO {$this->table_prefix}_usermeta (user_id, meta_key, meta_value) VALUES (:user_id, :meta_key, :meta_value)");
            $insert->execute([':user_id' => $user_id, ':meta_key' => $meta_key, ':meta_value' => $meta_value]);
        }
    }
}
if (php_sapi_name() === 'cli' && ! defined('PHPUNIT_RUNNING')) {

try {
    $consumer = new CompanyConsumer(); // geen connectie met queue of db in tests
    $consumer->run();                  // expliciet starten
} catch (Exception $e) {
    error_log("❌ CompanyConsumer kon niet starten: " . $e->getMessage());
    exit(1);
}
}