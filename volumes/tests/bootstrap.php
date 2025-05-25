<?php

// dit is je PHPUnit-bootstrap

// 1️⃣ laad je Composer-autoloader
require_once __DIR__ . '/../vendor/autoload.php';
// 2️⃣ definieer WP-stubs, maar alleen als ze nog niet bestaan
if (! function_exists('get_userdata')) {
    function get_userdata($id) {
        if ($id === 999) {
            return null;
        }
        return (object)[
            'ID'         => $id,
            'user_email' => "user{$id}@example.com",
            'user_pass'  => "pass{$id}"
        ];
    }
}

if (! function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single) {
        if ($key === 'uid')                    return null;
        if ($key === 'old_company_vat_number') return 'VAT123';
        return "meta_{$key}";
    }
}

if (! function_exists('update_user_meta')) {
    function update_user_meta($uid, $key, $value) { return true; }
}

if (! function_exists('delete_user_meta')) {
    function delete_user_meta($uid, $key)       { return true; }
}

if (! function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['transient_return'] ?? null;
    }
}

if (! function_exists('set_transient')) {
    function set_transient($key, $value, $expire) {
        $GLOBALS['last_set_transient'] = compact('key','value','expire');
        return true;
    }
}

// stub WPDB
if (! class_exists('WPDB')) {
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
}
if (!defined('ARRAY_A')) define('ARRAY_A', 1);


// Instantieer $wpdb als het nog niet bestaat
global $wpdb;
if (! $wpdb instanceof WPDB) {
    $wpdb = new WPDB();
}


define('PHPUNIT_RUNNING', true);
