<?php
// Load WordPress environment
require_once dirname(__DIR__) . '/wp-load.php';

global $wpdb;

// User ID to test
$user_id = 6; // Replace with the desired user ID if needed

// Test 1: Fetch user data from wp_users
$user = get_userdata($user_id);
if ($user) {
    echo "User Email for ID $user_id: " . $user->user_email . "\n";
} else {
    echo "User with ID $user_id not found.\n";
}

// Test 2: Fetch from wp_usermeta
$birth_date = get_user_meta($user_id, 'birth_date', true);
$phone_number = get_user_meta($user_id, 'phone_number', true);
echo "Birth Date for ID $user_id: " . ($birth_date ?: 'Not found') . "\n";
echo "Phone Number for ID $user_id: " . ($phone_number ?: 'Not found') . "\n";

// Test 3: Fetch all usermeta for debugging
$all_usermeta = get_user_meta($user_id);
echo "All User Meta for ID $user_id: " . print_r($all_usermeta, true) . "\n";

// Test 4: Fetch from wp_um_metadata
$um_table = $wpdb->prefix . 'um_metadata';
echo "Querying table: $um_table for user_id $user_id\n";

$um_metadata = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT um_key, um_value FROM $um_table WHERE user_id = %d",
        $user_id
    ),
    ARRAY_A
);

// Output the query and results
echo "Last SQL Query: " . $wpdb->last_query . "\n";
if (!empty($um_metadata)) {
    echo "UM Metadata for ID $user_id: " . print_r($um_metadata, true) . "\n";

    // Map UM metadata to variables
    $um_data = [];
    foreach ($um_metadata as $meta) {
        $um_data[$meta['um_key']] = $meta['um_value'];
    }
    echo "Mapped UM Data for ID $user_id: " . print_r($um_data, true) . "\n";
} else {
    echo "No UM metadata found for user ID $user_id\n";
}
?>