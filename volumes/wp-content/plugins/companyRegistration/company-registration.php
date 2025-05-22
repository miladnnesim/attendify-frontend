<?php
/**
 * Plugin Name: Company Registration Form
 * Description: A custom plugin to create a "Register as Company" form using a shortcode.
 * Version: 1.2
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
    <input type="text" name="company_address" id="company_address" required><br>

    <label for="street_number">Number:</label>
    <input type="text" name="street_number" id="street_number" required><br>

    <label for="postal_code">Postal Code:</label>
    <input type="text" name="postal_code" id="postal_code" required><br>

    <label for="city">City:</label>
    <input type="text" name="city" id="city" required><br>

    <!-- Billing Address Options -->
    <label><strong>Billing address:</strong></label><br>
    <input type="radio" name="billing_option" value="same" id="billing_same">
    <label for="billing_same">Billing address is the same as company address</label><br>

    <input type="radio" name="billing_option" value="custom" id="billing_custom" checked>
    <label for="billing_custom">I want to fill in a different billing address</label><br><br>

    <!-- Billing Address Fields -->
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
document.addEventListener('DOMContentLoaded', function() {
    const sameRadio = document.getElementById('billing_same');
    const customRadio = document.getElementById('billing_custom');
    const form = document.querySelector('form');

    function toggleBillingFields(isSame) {
        const map = {
            'company_address': 'billing_address',
            'street_number': 'billing_street_number',
            'postal_code': 'billing_postal_code',
            'city': 'billing_city'
        };

        for (const [fromId, toId] of Object.entries(map)) {
            const fromEl = document.getElementById(fromId);
            const toEl = document.getElementById(toId);
            if (!fromEl || !toEl) continue;

            if (isSame) {
                toEl.value = fromEl.value;
                toEl.setAttribute('readonly', true);
                toEl.removeAttribute('required');
            } else {
                toEl.value = '';
                toEl.removeAttribute('readonly');
                toEl.setAttribute('required', true);
            }
        }
    }

    if (sameRadio) {
        sameRadio.addEventListener('change', function() {
            if (this.checked) toggleBillingFields(true);
        });
    }

    if (customRadio) {
        customRadio.addEventListener('change', function() {
            if (this.checked) toggleBillingFields(false);
        });
    }

    form.addEventListener('submit', function() {
        if (sameRadio && sameRadio.checked) {
            toggleBillingFields(true);
        }
    });
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

        // ⬇️ Copy company address to billing address if "same address" is selected

        $billing_option = $_POST['billing_option'] ?? 'custom';
        if ($billing_option === 'same') {
            $_POST['billing_address'] = $_POST['company_address'] ?? '';
            $_POST['billing_street_number'] = $_POST['street_number'] ?? '';
            $_POST['billing_postal_code'] = $_POST['postal_code'] ?? '';
            $_POST['billing_city'] = $_POST['city'] ?? '';
        }

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

    // 🔁 Update
    if (isset($_POST['update_uid'])) {
        $uid = sanitize_text_field($_POST['update_uid']);

        // ⬇️ Background autofill if "same address" is selected

        $billing_option = $_POST["billing_option_$uid"] ?? 'custom';
        if ($billing_option === 'same') {
            $_POST['billing_address'] = $_POST['company_address'] ?? '';
            $_POST['billing_street_number'] = $_POST['street_number'] ?? '';
            $_POST['billing_postal_code'] = $_POST['postal_code'] ?? '';
            $_POST['billing_city'] = $_POST['city'] ?? '';
        }

        $updated_data = [
            'name' => sanitize_text_field($_POST['company_name']),
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
        ];

        $wpdb->update('companies', $updated_data, ['uid' => $uid]);
        $updated_data['uid'] = $uid;
        $producer->sendCompanyData($updated_data, 'update');
    }

    // 🗑️ Delete
    if (isset($_POST['delete_uid'])) {
        $uid = sanitize_text_field($_POST['delete_uid']);
        $wpdb->delete('companies', ['uid' => $uid]);
        $producer->sendCompanyData(['uid' => $uid], 'delete');
    }

    // Load data
    $companies = $wpdb->get_results($wpdb->prepare("SELECT * FROM companies WHERE owner_id = %s", $owner_uid));
    if (empty($companies)) return "<p>📭 Geen bedrijven gevonden.</p>";

   
    $output = "<h3>🗂️ Mijn Bedrijven</h3><ul>";
    foreach ($companies as $c) {
        $uid = esc_attr($c->uid);
        $output .= "
        <li>
            <strong>{$c->name}</strong><br>
            <button type='button' onclick=\"document.getElementById('edit-$uid').style.display='block'\">✏️ Bewerken</button>
            <form method='POST' style='display:inline;'>
                <input type='hidden' name='delete_uid' value='$uid'>
                <button type='submit'>🗑️ Verwijderen</button>
            </form>
            <div id='edit-$uid' style='display:none; margin-top:10px;'>
                <form method='POST'>
                    <input type='hidden' name='update_uid' value='$uid'>

                    <label>Naam:</label><input name='company_name' id='company_name_$uid' value='" . esc_attr($c->name) . "' required><br>
                    <label>Straat:</label><input name='company_address' id='company_address_$uid' value='" . esc_attr($c->street) . "' required><br>
                    <label>Nummer:</label><input name='street_number' id='street_number_$uid' value='" . esc_attr($c->number) . "' required><br>
                    <label>Postcode:</label><input name='postal_code' id='postal_code_$uid' value='" . esc_attr($c->postcode) . "' required><br>
                    <label>Stad:</label><input name='city' id='city_$uid' value='" . esc_attr($c->city) . "' required><br>

                    <label><strong>Billing address:</strong></label><br>
                    <input type='radio' name='billing_option_$uid' value='same' id='billing_same_$uid'>
                    <label for='billing_same_$uid'>Same as company address</label><br>
                    <input type='radio' name='billing_option_$uid' value='custom' id='billing_custom_$uid' checked>
                    <label for='billing_custom_$uid'>Enter a different billing address</label><br><br>

                    <label>Billing Street:</label><input name='billing_address' id='billing_address_$uid' value='" . esc_attr($c->billing_street) . "' required><br>
                    <label>Billing Number:</label><input name='billing_street_number' id='billing_street_number_$uid' value='" . esc_attr($c->billing_number) . "' required><br>
                    <label>Billing Postal Code:</label><input name='billing_postal_code' id='billing_postal_code_$uid' value='" . esc_attr($c->billing_postcode) . "' required><br>
                    <label>Billing City:</label><input name='billing_city' id='billing_city_$uid' value='" . esc_attr($c->billing_city) . "' required><br>

                    <label>Email:</label><input name='email' value='" . esc_attr($c->email) . "' required><br>
                    <label>Phone:</label><input name='phone' value='" . esc_attr($c->phone) . "' required><br><br>

                    <input type='submit' value='💾 Opslaan'>
                    <button type='button' onclick=\"document.getElementById('edit-$uid').style.display='none'\">Annuleren</button>
                </form>
            </div>
        </li><br>";
    }
    $output .= "</ul>";

    // ✅ Javascript
    $output .= "<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form').forEach(function(form) {
        const uidField = form.querySelector('input[name=\"update_uid\"]');
        if (!uidField) return;
        const uid = uidField.value;

        const radioSame = document.getElementById('billing_same_' + uid);
        const radioCustom = document.getElementById('billing_custom_' + uid);

        function toggleBillingFields(isSame) {
            const map = {
                'company_address': 'billing_address',
                'street_number': 'billing_street_number',
                'postal_code': 'billing_postal_code',
                'city': 'billing_city'
            };
            for (const [fromKey, toKey] of Object.entries(map)) {
                const fromEl = document.getElementById(fromKey + '_' + uid);
                const toEl = document.getElementById(toKey + '_' + uid);
                if (!fromEl || !toEl) continue;

                if (isSame) {
                    toEl.value = fromEl.value;
                    toEl.setAttribute('readonly', true);
                    toEl.removeAttribute('required');
                } else {
                    toEl.value = '';
                    toEl.removeAttribute('readonly');
                    toEl.setAttribute('required', true);
                }
            }
        }

        if (radioSame) {
            radioSame.addEventListener('change', function () {
                if (this.checked) toggleBillingFields(true);
            });
        }

        if (radioCustom) {
            radioCustom.addEventListener('change', function () {
                if (this.checked) toggleBillingFields(false);
            });
        }

        form.addEventListener('submit', function () {
            if (radioSame && radioSame.checked) {
                toggleBillingFields(true);
            }
        });
    });
});
</script>";

    return $output;
}