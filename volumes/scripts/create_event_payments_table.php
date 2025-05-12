<?php

//BATELINEN-TABEL CREËEREN
$host = getenv('LOCAL_DB_HOST');
$db = getenv('LOCAL_DB_NAME');
$user = getenv('LOCAL_DB_USER');
$password = getenv('LOCAL_DB_PASSWORD');

$mysqli = new mysqli($host, $user, $password, $db);

if ($mysqli->connect_error) {
    die("❌ Connection failed: " . $mysqli->connect_error);
}

$sql = "
CREATE TABLE IF NOT EXISTS `event_payments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `uid` VARCHAR(255) NOT NULL,
    `event_id` VARCHAR(255) NOT NULL,
    `entrance_fee` DECIMAL(10,2) NOT NULL,
    `entrance_paid` BOOLEAN NOT NULL DEFAULT FALSE,
    `paid_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
";

if ($mysqli->query($sql) === TRUE) {
    echo "✅ Table event_payments created successfully.\n";
} else {
    echo "❌ Error creating table: " . $mysqli->error . "\n";
}

$mysqli->close();
?>
