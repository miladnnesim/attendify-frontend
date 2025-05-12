<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/producer.php'; // Zorg dat dit pad klopt

class TestProducer extends Producer {
    public function testXMLGeneration($user_id, $operation) {
        ob_start();
        $this->sendUserData($user_id, $operation); // laat Producer de XML genereren
        $output = ob_get_clean();

        // Sla de XML op in een bestand voor controle
        file_put_contents(__DIR__ . '/test_output.xml', $output);
        echo "✅ XML gegenereerd en opgeslagen in test_output.xml\n";
    }
}

$test = new TestProducer();
$test->testXMLGeneration(1, 'create'); // test voor user ID 1
