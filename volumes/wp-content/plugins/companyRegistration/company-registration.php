<?php
/**
 * Plugin Name: Company Registration Form
 * Description: A custom plugin to create a "Register as Company" form using a shortcode.
 * Version: 1.0
 * Author: Attendify
 */

require_once plugin_dir_path(__FILE__) . '../../../rabbitmq/producercompany.php';

function company_register_form_shortcode() {
    ob_start();
    ?>
<form action="" method="POST">
    <!-- Company Information -->
    <label for="company_name">Name of the company:</label>
    <input type="text" name="company_name" required><br>

    <label for="uid">UID:</label>
    <input type="text" name="uid" required><br>

    <label for="business_number">Business Number:</label>
    <input type="text" name="business_number" required><br>

    <label for="vat_number">VAT Number:</label>
    <input type="text" name="vat_number" required><br>

    <!-- Company Address -->
    <label for="company_address">Street:</label>
    <input type="text" name="company_address" required><br>

    <label for="street_number">Number:</label>
    <input type="text" name="street_number" required><br>

    <label for="postal_code">Postal Code:</label>
    <input type="text" name="postal_code" required><br>

    <label for="city">City:</label>
    <input type="text" name="city" required><br>

    <!-- Billing Address -->
    <label for="billing_address">Billing Street:</label>
    <input type="text" name="billing_address" required><br>

    <label for="billing_street_number">Billing Number:</label>
    <input type="text" name="billing_street_number" required><br>

    <label for="billing_postal_code">Billing Postal Code:</label>
    <input type="text" name="billing_postal_code" required><br>

    <label for="billing_city">Billing City:</label>
    <input type="text" name="billing_city" required><br>

    <!-- Contact Information -->
    <label for="email">Email:</label>
    <input type="email" name="email" required><br>

    <label for="phone">Phone:</label>
    <input type="text" name="phone" required><br>

    <input type="submit" value="Register">
</form>
<?php
    $success = false;

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $company_data = [
            'uid' => sanitize_text_field($_POST['uid'] ?? ''),
            'companyNumber' => sanitize_text_field($_POST['business_number'] ?? ''),
            'name' => sanitize_text_field($_POST['company_name'] ?? ''),
            'VATNumber' => sanitize_text_field($_POST['vat_number'] ?? ''),
            'street' => sanitize_text_field($_POST['company_address'] ?? ''),
            'number' => sanitize_text_field($_POST['street_number'] ?? ''),
            'postcode' => sanitize_text_field($_POST['postal_code'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'billing_street' => sanitize_text_field($_POST['billing_address'] ?? ''),
            'billing_number' => sanitize_text_field($_POST['billing_street_number'] ?? ''),
            'billing_postcode' => sanitize_text_field($_POST['billing_postal_code'] ?? ''),
            'billing_city' => sanitize_text_field($_POST['billing_city'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? '')
        ];

        try {
            $producer = new CompanyProducer();
            $producer->sendCompanyData($company_data, 'register');
            $success = true;
        } catch (Exception $e) {
            echo "<p style='color:red;'>❌ Something went wrong while sending the data. Please try again later.</p>";
            error_log("RabbitMQ Error: " . $e->getMessage());
        }
    }

    if ($success) {
        echo "<p style='color:green;'>✅ The company was successfully submitted for processing.</p>";
    }

    return ob_get_clean();
}

add_shortcode('company_register_form', 'company_register_form_shortcode');