<?php
/**
 * Plugin Name: Company Registration Form
 * Description: A custom plugin to create a "Register as Company" form using a shortcode.
 * Version: 1.1
 * Author: Attendify
 */

require_once plugin_dir_path(__FILE__) . '../../../rabbitmq/producercompany.php';

function company_register_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<p style="color:red;">❌ You must be logged in to register a company.</p>';
    }

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
    <label>
        <input type="checkbox" id="copy_address_checkbox"> Billing address is same as company address
    </label><br>

    <label for="billing_address">Billing Street:</label>
    <input type="text" name="billing_address" id="billing_address" required><br>

    <label for="billing_street_number">Billing Number:</label>
    <input type="text" name="billing_street_number" id="billing_street_number" required><br>

    <label for="billing_postal_code">Billing Postal Code:</label>
    <input type="text" name="billing_postal_code" id="billing_postal_code" required><br>

    <label for="billing_city">Billing City:</label>
    <input type="text" name="billing_city" id="billing_city" required><br>

    <!-- Contact Information -->
    <label for="email">Email:</label>
    <input type="email" name="email" required><br>

    <label for="phone">Phone:</label>
    <input type="text" name="phone" required><br>

    <input type="submit" value="Register">
</form>

<script>
document.getElementById('copy_address_checkbox').addEventListener('change', function() {
    if (this.checked) {
        if (document.getElementById('billing_address').value === '') {
            document.getElementById('billing_address').value = document.querySelector(
                '[name="company_address"]').value;
        }
        if (document.getElementById('billing_street_number').value === '') {
            document.getElementById('billing_street_number').value = document.querySelector(
                '[name="street_number"]').value;
        }
        if (document.getElementById('billing_postal_code').value === '') {
            document.getElementById('billing_postal_code').value = document.querySelector(
                '[name="postal_code"]').value;
        }
        if (document.getElementById('billing_city').value === '') {
            document.getElementById('billing_city').value = document.querySelector('[name="city"]').value;
        }
    }
});
</script>
<?php

    $success = false;

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        global $wpdb;
        $table_name = 'companies';

        $uid = 'WP' . time();
        $current_user = wp_get_current_user();
        $owner_id = get_user_meta($current_user->ID, 'uid', true);

        $company_data = [
            'uid' => $uid,
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
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'owner_id' => $owner_id
        ];

        $inserted = $wpdb->insert($table_name, $company_data);
        $success = $inserted !== false;
    }

    if ($success) {
        $producer = new CompanyProducer();
        $producer->sendCompanyData($company_data, 'create');
        echo "<p style='color:green;'>✅ The company was successfully saved in the database.</p>";
    }

    return ob_get_clean();
}

add_shortcode('company_register_form', 'company_register_form_shortcode');


add_shortcode('my_companies', 'show_user_companies');
function show_user_companies() {
    if (!is_user_logged_in()) return '⛔ Je moet ingelogd zijn.';

    global $wpdb;
    require_once plugin_dir_path(__FILE__) . '../../../rabbitmq/producercompany.php';
    $current_user = wp_get_current_user();
    $owner_uid = get_user_meta($current_user->ID, 'uid', true);
    $producer = new CompanyProducer();

    // DELETE
    if (isset($_POST['delete_uid'])) {
        $uid = sanitize_text_field($_POST['delete_uid']);
        $wpdb->delete('companies', ['uid' => $uid, 'owner_id' => $owner_uid]);
        $producer->sendCompanyData(['uid' => $uid], 'delete');
    }

    // UPDATE
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_uid'])) {
        $update_uid = sanitize_text_field($_POST['update_uid']);
        $company_data = [
            'uid' => $update_uid,
            'companyNumber' => sanitize_text_field($_POST['business_number']),
            'name' => sanitize_text_field($_POST['company_name']),
            'VATNumber' => sanitize_text_field($_POST['vat_number']),
            'street' => sanitize_text_field($_POST['company_address']),
            'number' => sanitize_text_field($_POST['street_number']),
            'postcode' => sanitize_text_field($_POST['postal_code']),
            'city' => sanitize_text_field($_POST['city']),
            'billing_street' => sanitize_text_field($_POST['billing_address']),
            'billing_number' => sanitize_text_field($_POST['billing_street_number']),
            'billing_postcode' => sanitize_text_field($_POST['billing_postal_code']),
            'billing_city' => sanitize_text_field($_POST['billing_city']),
            'email' => sanitize_email($_POST['email']),
            'phone' => sanitize_text_field($_POST['phone']),
            'owner_id' => $owner_uid
        ];
        $wpdb->update('companies', $company_data, ['uid' => $update_uid, 'owner_id' => $owner_uid]);
        $producer->sendCompanyData($company_data, 'update');
    }

    // LAAD BEDRIJVEN
    $companies = $wpdb->get_results($wpdb->prepare("SELECT * FROM companies WHERE owner_id = %s", $owner_uid));
    if (empty($companies)) return "<p>📭 Geen bedrijven gevonden.</p>";

    $output = "<h3>🗂️ Mijn Bedrijven</h3><ul>";
    foreach ($companies as $c) {
        $output .= "
            <li>
                <strong>{$c->name}</strong> <br>
                <button onclick=\"document.getElementById('edit-{$c->uid}').style.display='block'\">✏️ Bewerken</button>
                <form method='POST' onsubmit=\"return confirm('Weet je zeker dat je dit bedrijf wil verwijderen?')\" style='display:inline'>
                    <input type='hidden' name='delete_uid' value='{$c->uid}'>
                    <button type='submit'>🗑️ Verwijderen</button>
                </form>
                <div id='edit-{$c->uid}' style='display:none; margin-top:10px;'>
                    <h4>Bewerken: {$c->name}</h4>
                    <form method='POST'>
                        <input type='hidden' name='update_uid' value='{$c->uid}'>
                        <label>Naam:</label><input name='company_name' value='" . esc_attr($c->name) . "' required><br>
                        <label>Business Nummer:</label><input name='business_number' value='" . esc_attr($c->companyNumber) . "' required><br>
                        <label>BTW Nummer:</label><input name='vat_number' value='" . esc_attr($c->VATNumber) . "' required><br>
                        <label>Straat:</label><input name='company_address' value='" . esc_attr($c->street) . "' required><br>
                        <label>Nummer:</label><input name='street_number' value='" . esc_attr($c->number) . "' required><br>
                        <label>Postcode:</label><input name='postal_code' value='" . esc_attr($c->postcode) . "' required><br>
                        <label>Stad:</label><input name='city' value='" . esc_attr($c->city) . "' required><br>

                        <label>
                            <input type='checkbox' class='copy_address_checkbox' data-id='{$c->uid}'>
                            Billing address is same as company address
                        </label><br>

                        <label>Facturatie Straat:</label><input name='billing_address' class='billing_address_{$c->uid}' value='" . esc_attr($c->billing_street) . "' required><br>
                        <label>Facturatie Nummer:</label><input name='billing_street_number' class='billing_street_number_{$c->uid}' value='" . esc_attr($c->billing_number) . "' required><br>
                        <label>Facturatie Postcode:</label><input name='billing_postal_code' class='billing_postal_code_{$c->uid}' value='" . esc_attr($c->billing_postcode) . "' required><br>
                        <label>Facturatie Stad:</label><input name='billing_city' class='billing_city_{$c->uid}' value='" . esc_attr($c->billing_city) . "' required><br>

                        <label>Email:</label><input name='email' value='" . esc_attr($c->email) . "' required><br>
                        <label>Telefoon:</label><input name='phone' value='" . esc_attr($c->phone) . "' required><br><br>
                        <input type='submit' value='💾 Opslaan'>
                        <button type='button' onclick=\"document.getElementById('edit-{$c->uid}').style.display='none'\">Annuleren</button>
                    </form>
                </div>
            </li><br>";
    }
    $output .= "</ul>";

    // JavaScript for copying billing address
    $output .= "
    <script>
    document.querySelectorAll('.copy_address_checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function () {
            const uid = this.dataset.id;
            if (this.checked) {
                const company_street = document.querySelector(`[name='company_address']`).value;
                const company_number = document.querySelector(`[name='street_number']`).value;
                const company_postcode = document.querySelector(`[name='postal_code']`).value;
                const company_city = document.querySelector(`[name='city']`).value;

                const billing_street = document.querySelector(`.billing_address_${uid}`);
                const billing_number = document.querySelector(`.billing_street_number_${uid}`);
                const billing_postcode = document.querySelector(`.billing_postal_code_${uid}`);
                const billing_city = document.querySelector(`.billing_city_${uid}`);

                if (billing_street.value.trim() === '') billing_street.value = company_street;
                if (billing_number.value.trim() === '') billing_number.value = company_number;
                if (billing_postcode.value.trim() === '') billing_postcode.value = company_postcode;
                if (billing_city.value.trim() === '') billing_city.value = company_city;
            }
        });
    });
    </script>";

    return $output;
}