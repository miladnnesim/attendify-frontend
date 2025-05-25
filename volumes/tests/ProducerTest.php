<?php
// tests/ProducerTest.php

namespace {
    // --- Global stubs voor WordPress-functies en $wpdb ---
    function get_userdata($id) {
        if ($id === 999) {
            return null;
        }
        return (object) [
            'ID'         => $id,
            'user_email' => "user{$id}@example.com",
            'user_pass'  => "pass{$id}"
        ];
    }

    function get_user_meta($uid, $key, $single) {
        if ($key === 'uid') {
            return null;
        }
        if ($key === 'old_company_vat_number') {
            return '';
        }
        return "meta_{$key}";
    }

    function update_user_meta($uid, $key, $value) { return true; }
    function delete_user_meta($uid, $key)       { return true; }

    function get_transient($key) {
        return $GLOBALS['transient_return'] ?? null;
    }
    function set_transient($key, $value, $expire) {
        $GLOBALS['last_set_transient'] = compact('key','value','expire');
        return true;
    }

    class WPDB {
        public $prefix = 'wp_';
        public function prepare($query, $uid) {
            return "SELECT um_key, um_value FROM {$this->prefix}um_metadata WHERE user_id = $uid";
        }
        public function get_results($query, $output) {
            return [
                ['um_key' => 'first_name', 'um_value' => 'OverrideFirst']
            ];
        }
    }

    /** @var WPDB $wpdb */
    global $wpdb;
    $wpdb = new WPDB();
}

namespace Tests {
    use PHPUnit\Framework\TestCase;
    use App\Producer;
    use PhpAmqpLib\Message\AMQPMessage;
    use PhpAmqpLib\Channel\AMQPChannel;

    class ProducerTest extends TestCase
    {
        public function testBuildUserXmlProducesExpectedStructure(): void
        {
            $mockChannel = $this->createMock(AMQPChannel::class);
            $producer = new Producer($mockChannel);

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

            $this->assertStringContainsString('<sender>frontend</sender>', $xml);
            $this->assertStringContainsString('<operation>create</operation>', $xml);
            $this->assertStringContainsString('<uid>UID123</uid>', $xml);
            $this->assertStringContainsString('<first_name>John</first_name>', $xml);
            $this->assertStringContainsString('<email>foo@bar.com</email>', $xml);
            $this->assertStringContainsString('<VAT_number>VAT123</VAT_number>', $xml);
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

            $producer = new Producer($mockChannel);
            $producer->sendUserData(1, 'create');
        }

        public function testSendUserDataSkipsDuplicateMessage(): void
        {
            $mockChannel = $this->createMock(AMQPChannel::class);
            $mockChannel->expects($this->never())->method('basic_publish');

            $producer = new Producer($mockChannel);
            $xml  = $producer->buildUserXml('WP'.time(), get_userdata(1), [], 'update');
            $hash = md5($xml);
            $GLOBALS['transient_return'] = $hash;

            $producer->sendUserData(1, 'update');

            $this->assertArrayHasKey('last_set_transient', $GLOBALS);
        }

        public function functionProvider(): array
        {
            return [
                ['create', 'user.register'],
                ['update', 'user.update'],
                ['delete', 'user.delete'],
            ];
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

            $producer = new Producer($mockChannel);
            $producer->sendUserData(2, $operation);
        }

        public function testSendUserDataInvalidOperationThrows(): void
        {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("Invalid operation: foo");

            $producer = new Producer($this->createMock(AMQPChannel::class));
            $producer->sendUserData(1, 'foo');
        }

        public function testSendUserDataUserNotFoundThrows(): void
        {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("User with ID 999 not found.");

            $producer = new Producer($this->createMock(AMQPChannel::class));
            $producer->sendUserData(999, 'create');
        }
    }
}
