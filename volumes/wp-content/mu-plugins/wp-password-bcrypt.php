<?php
/**
* Plugin Name: WP Password bcrypt
* Plugin URI: https://github.com/roots/wp-password-bcrypt
* Description: Replaces wp_hash_password and wp_check_password with password_hash and password_verify for secure bcrypt
* Author: Roots
* Author URI: https://roots.io
* Version: 1.1.0
* Licence: MIT
*/

/**
* Verifies if the provided plaintext password matches the hashed password.
* If the hash is not bcrypt, it re-hashes the password to bcrypt.
*
* @param string $password The plaintext password.
* @param string $hash The hashed password to verify against.
* @param string|int $user_id (optional) User ID to check against.
* @return bool
*/
function wp_check_password($password, $hash, $user_id = '') {
if (!password_needs_rehash($hash, PASSWORD_DEFAULT, apply_filters('wp_hash_password_options', []))) {
// If hash doesn't need rehashing, verify the password
return apply_filters('check_password', password_verify($password, $hash), $password, $hash, $user_id);
}

global $wp_hasher;

if (empty($wp_hasher)) {
// Load the phpass class if bcrypt is needed
require_once ABSPATH . WPINC . '/class-phpass.php';
$wp_hasher = new PasswordHash(8, true);
}

// If password is valid using phpass, update it to bcrypt
if (!empty($user_id) && $wp_hasher->CheckPassword($password, $hash)) {
$hash = wp_set_password($password, $user_id);
}

return apply_filters('check_password', password_verify($password, $hash), $password, $hash, $user_id);
}

/**
* Hashes the provided password using bcrypt (PASSWORD_DEFAULT).
*
* @param string $password The plaintext password.
* @return string The bcrypt hashed password.
*/
function wp_hash_password($password) {
return password_hash($password, PASSWORD_DEFAULT, apply_filters('wp_hash_password_options', []));
}

/**
* Hash and update the user's password.
* This function sets the password hash using bcrypt.
*
* @param string $password The new plaintext password.
* @param int $user_id The user ID for whom the password is set.
* @return string The newly hashed password.
*/
function wp_set_password($password, $user_id) {
$old_user_data = get_userdata($user_id);
$hash = wp_hash_password($password);

$is_api_request = apply_filters(
'application_password_is_api_request',
(defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) ||
(defined('REST_REQUEST') && REST_REQUEST)
);

if (!$is_api_request) {
global $wpdb;

// Update password in the database
$wpdb->update($wpdb->users, [
'user_pass' => $hash,
'user_activation_key' => ''
], ['ID' => $user_id]);

clean_user_cache($user_id);

// Trigger an action after password is set
do_action('wp_set_password', $password, $user_id, $old_user_data);

return $hash;
}

// Update password for API-based application passwords
if (!class_exists('WP_Application_Passwords') || empty($passwords =
WP_Application_Passwords::get_user_application_passwords($user_id))) {
return $hash;
}

global $wp_hasher;

if (empty($wp_hasher)) {
require_once ABSPATH . WPINC . '/class-phpass.php';
$wp_hasher = new PasswordHash(8, true);
}

// Update the application passwords
foreach ($passwords as $key => $value) {
if (!$wp_hasher->CheckPassword($password, $value['password'])) {
continue;
}

$passwords[$key]['password'] = $hash;
}

update_user_meta(
$user_id,
WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS,
$passwords
);

return $hash;
}