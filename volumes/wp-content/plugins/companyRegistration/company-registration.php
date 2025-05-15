<?php
/**
 * Plugin Name: Company Registration Form
 * Description: A custom plugin to create a "Register as Company" form using a shortcode.
 * Version: 1.0
 * Author: Attendify
 */

// Include the RabbitMQ Producer class
require_once plugin_dir_path(__FILE__) . '../../../rabbitmq/producercompany.php';

function company_register_form_shortcode() {
    ob_start();
    ?>

<form action="" method="POST">
    <!-- Company Information -->
    <label for="company_name">Name of the company:</label>
    <input type="text" name="company_name" required><br>

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
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {

        // Collect and sanitize input values
        $company_name = sanitize_text_field($_POST['company_name'] ?? '');
        $business_number = sanitize_text_field($_POST['business_number'] ?? '');
        $vat_number = sanitize_text_field($_POST['vat_number'] ?? '');
        $company_address = sanitize_text_field($_POST['company_address'] ?? '');
        $street_number = sanitize_text_field($_POST['street_number'] ?? '');
        $postal_code = sanitize_text_field($_POST['postal_code'] ?? '');
        $city = sanitize_text_field($_POST['city'] ?? '');
        $billing_address = sanitize_text_field($_POST['billing_address'] ?? '');
        $billing_street_number = sanitize_text_field($_POST['billing_street_number'] ?? '');
        $billing_postal_code = sanitize_text_field($_POST['billing_postal_code'] ?? '');
        $billing_city = sanitize_text_field($_POST['billing_city'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');

        // Organize the data into an array to send it to RabbitMQ
        $company_data = [
            'ondernemingsnummer' => $business_number,
            'naam' => $company_name,
            'btwnummer' => $vat_number,
            'straat' => $company_address,
            'nummer' => $street_number,
            'postcode' => $postal_code,
            'gemeente' => $city,
            'facturatie_straat' => $billing_address,
            'facturatie_nummer' => $billing_street_number,
            'facturatie_postcode' => $billing_postal_code,
            'facturatie_gemeente' => $billing_city,
            'email' => $email,
            'telefoon' => $phone
        ];

        // Send the company data to RabbitMQ
        try {
            // Initialize the producer and send the message
            $producer = new CompanyProducer();
            $producer->sendCompanyData($company_data, 'register'); // or 'create' if you prefer

            // Success message for the user
            echo "<p style='color:green;'>✅ The company was successfully submitted for processing.</p>";
        } catch (Exception $e) {
            // Friendly error message for the user
            echo "<p style='color:red;'>❌ Something went wrong while sending the data. Please try again later.</p>";

            // Log the technical error for developers
            error_log("RabbitMQ Error: " . $e->getMessage());
        }
    }

    return ob_get_clean();
}

// Register the shortcode so the form can be used in WordPress pages
add_shortcode('company_register_form', 'company_register_form_shortcode');