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
        return '<div class="alert alert-error">You must be logged in to register a company</div>';
    }

    ob_start();

     ?>
<style>
.crf-form {
    max-width: 700px;
    margin: 0 auto;
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
    font-size: 15px;
    color: #333;
}
.form-group {
    margin-bottom: 1.2rem;
}
.form-label {
    display: block;
    margin-bottom: 0.3rem;
    font-weight: 500;
}
.form-control {
    width: 100%;
    box-sizing: border-box;
    padding: 0.55rem 0.8rem;
    font-size: 15px;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
    background-color: #fafafa;
    transition: border 0.2s ease-in-out, background 0.2s;
}
.form-control:focus {
    border-color: #888;
    background-color: #fff;
    outline: none;
}
fieldset {
    border: 1px solid #ddd;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}
legend {
    font-size: 1rem;
    font-weight: 600;
    padding: 0 0.5rem;
}
input[type="radio"] {
    margin-right: 6px;
}
.btn {
    padding: 0.6rem 1.2rem;
    font-size: 14px;
    border: 1px solid #bbb;
    border-radius: 6px;
    cursor: pointer;
    background-color: #f4f4f4;
    color: #333;
    transition: background-color 0.2s, border-color 0.2s;
}
.btn:hover {
    background-color: #e8e8e8;
    border-color: #999;
}
.btn-primary {
    background-color: #f0f0f0;
    border-color: #ccc;
    color: #333;
}
.btn-primary:hover {
    background-color: #e0e0e0;
    border-color: #999;
    color: #000;
}
.alert {
    padding: 0.8rem 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}
.alert-success {
    background-color: #e6f4ea;
    color: #276738;
    border: 1px solid #cde7d7;
}
.alert-error {
    background-color: #fbeaea;
    color: #7a1c1c;
    border: 1px solid #f5cccc;
}
.billing-fields.hidden {
    display: none;
}
</style>
    <form class="crf-form" action="" method="POST" novalidate>
        <div class="form-group">
            <label class="form-label" for="company_name">Company Name</label>
            <input class="form-control" type="text" name="company_name" id="company_name" required maxlength="255">
        </div>
        <div class="form-group">
            <label class="form-label" for="business_number">Business Number</label>
            <input class="form-control" type="text" name="business_number" id="business_number" required maxlength="20">
        </div>
        <div class="form-group">
            <label class="form-label" for="vat_number">VAT Number</label>
            <input class="form-control" type="text" name="vat_number" id="vat_number" required maxlength="20">
        </div>

        <fieldset class="form-group">
            <legend class="form-label">Company Address</legend>
            <div class="form-group">
                <label class="form-label" for="company_address">Street</label>
                <input class="form-control" type="text" name="company_address" id="company_address" required maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label" for="street_number">Number</label>
                <input class="form-control" type="text" name="street_number" id="street_number" required maxlength="10">
            </div>
            <div class="form-group">
                <label class="form-label" for="postal_code">Postal Code</label>
                <input class="form-control" type="text" name="postal_code" id="postal_code" required maxlength="10">
            </div>
            <div class="form-group">
                <label class="form-label" for="city">City</label>
                <input class="form-control" type="text" name="city" id="city" required maxlength="255">
            </div>
        </fieldset>

        <fieldset class="form-group">
            <legend class="form-label">Billing Address</legend>
            <label><input type="radio" name="billing_option" value="same" id="billing_same"> Same as company address</label>
            <label><input type="radio" name="billing_option" value="custom" id="billing_custom" checked> Different address</label>
        </fieldset>

        <div class="billing-fields">
            <div class="form-group">
                <label class="form-label" for="billing_address">Billing street</label>
                <input class="form-control" type="text" name="billing_address" id="billing_address" required maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label" for="billing_street_number">Billing number</label>
                <input class="form-control" type="text" name="billing_street_number" id="billing_street_number" required maxlength="10">
            </div>
            <div class="form-group">
                <label class="form-label" for="billing_postal_code">Billing postal Code</label>
                <input class="form-control" type="text" name="billing_postal_code" id="billing_postal_code" required maxlength="10">
            </div>
            <div class="form-group">
                <label class="form-label" for="billing_city">Billing city</label>
                <input class="form-control" type="text" name="billing_city" id="billing_city" required maxlength="255">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" type="email" name="email" id="email" required maxlength="255">
        </div>
        <div class="form-group">
            <label class="form-label" for="phone">Phone</label>
            <input class="form-control" type="text" name="phone" id="phone" required maxlength="50">
        </div>

        <button type="submit" class="btn btn-primary">Register</button>
    </form>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const same = document.getElementById('billing_same');
        const custom = document.getElementById('billing_custom');
        const billingGroup = document.querySelector('.billing-fields');

        const map = {
            company_address: 'billing_address',
            street_number: 'billing_street_number',
            postal_code: 'billing_postal_code',
            city: 'billing_city'
        };

        function toggle(isSame) {
            if (billingGroup) billingGroup.classList.toggle('hidden', isSame);

            Object.entries(map).forEach(([src, dst]) => {
                const from = document.getElementById(src);
                const to = document.getElementById(dst);
                if (!from || !to) return;

                if (isSame) {
                    to.value = from.value;
                    to.readOnly = true;
                    to.required = false;
                } else {
                    to.value = '';
                    to.readOnly = false;
                    to.required = true;
                }
            });
        }

        if (same) same.addEventListener('change', () => toggle(true));
        if (custom) custom.addEventListener('change', () => toggle(false));
        toggle(same && same.checked);
    });
    </script>
    <?php

    // Verwerking van POST-verzoek
    $success = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        global $wpdb;
        $table_name = 'companies';
        $uid = 'WP' . time();
        $current_user = wp_get_current_user();
        $owner_id = get_user_meta($current_user->ID, 'uid', true);

        $billing_option = $_POST['billing_option'] ?? 'custom';
        if ($billing_option === 'same') {
            $_POST['billing_address'] = $_POST['company_address'] ?? '';
            $_POST['billing_street_number'] = $_POST['street_number'] ?? '';
            $_POST['billing_postal_code'] = $_POST['postal_code'] ?? '';
            $_POST['billing_city'] = $_POST['city'] ?? '';
        }

        $company_data = [
            'uid' => $uid,
            'companyNumber' => substr(sanitize_text_field($_POST['business_number'] ?? ''), 0, 20),
            'name' => substr(sanitize_text_field($_POST['company_name'] ?? ''), 0, 255),
            'VATNumber' => substr(sanitize_text_field($_POST['vat_number'] ?? ''), 0, 20),
            'street' => substr(sanitize_text_field($_POST['company_address'] ?? ''), 0, 255),
            'number' => substr(sanitize_text_field($_POST['street_number'] ?? ''), 0, 10),
            'postcode' => substr(sanitize_text_field($_POST['postal_code'] ?? ''), 0, 10),
            'city' => substr(sanitize_text_field($_POST['city'] ?? ''), 0, 255),
            'billing_street' => substr(sanitize_text_field($_POST['billing_address'] ?? ''), 0, 255),
            'billing_number' => substr(sanitize_text_field($_POST['billing_street_number'] ?? ''), 0, 10),
            'billing_postcode' => substr(sanitize_text_field($_POST['billing_postal_code'] ?? ''), 0, 10),
            'billing_city' => substr(sanitize_text_field($_POST['billing_city'] ?? ''), 0, 255),
            'email' => substr(sanitize_email($_POST['email'] ?? ''), 0, 255),
            'phone' => substr(sanitize_text_field($_POST['phone'] ?? ''), 0, 50),
            'owner_id' => $owner_id
        ];

        $inserted = $wpdb->insert($table_name, $company_data);
        $success = $inserted !== false;

        if ($success) {
            $producer = new CompanyProducer();
            $producer->sendCompanyData($company_data, 'create');
            require_once plugin_dir_path(__FILE__) . '../../../rabbitmq/producer_user_link_company.php';
            sendUserCompanyLink($owner_id, $uid, 'register');

            echo '<div class="alert alert-success">The company has been registered successfully.</div>';
        } else {
            $error_msg = $wpdb->last_error ?: 'Unknown database error';
            echo '<div class="alert alert-error">Failed to register company: ' . esc_html($error_msg) . '</div>';
        }
    }

    return ob_get_clean();
}

add_shortcode('company_register_form', 'company_register_form_shortcode');

add_shortcode('my_companies', 'show_user_companies');

function show_user_companies() {
    if (!is_user_logged_in()) {
        return '<div class="alert alert-error">You must be logged in to view your companies.</div>';
    }

    global $wpdb;
    require_once plugin_dir_path(__FILE__) . '../../../rabbitmq/producercompany.php';

    $current_user = wp_get_current_user();
    $owner_uid = get_user_meta($current_user->ID, 'uid', true);
    $producer = new CompanyProducer();

    // Handle update
    if (isset($_POST['update_uid'])) {
        $uid = sanitize_text_field($_POST['update_uid']);
        $billing_option = $_POST["billing_option_$uid"] ?? 'custom';

        if ($billing_option === 'same') {
            $_POST['billing_address'] = $_POST['company_address'] ?? '';
            $_POST['billing_street_number'] = $_POST['street_number'] ?? '';
            $_POST['billing_postal_code'] = $_POST['postal_code'] ?? '';
            $_POST['billing_city'] = $_POST['city'] ?? '';
        }

        $updated_data = [
            'name' => substr(sanitize_text_field($_POST['company_name']), 0, 255),
            'street' => substr(sanitize_text_field($_POST['company_address']), 0, 255),
            'number' => substr(sanitize_text_field($_POST['street_number']), 0, 10),
            'postcode' => substr(sanitize_text_field($_POST['postal_code']), 0, 10),
            'city' => substr(sanitize_text_field($_POST['city']), 0, 255),
            'billing_street' => substr(sanitize_text_field($_POST['billing_address']), 0, 255),
            'billing_number' => substr(sanitize_text_field($_POST['billing_street_number']), 0, 10),
            'billing_postcode' => substr(sanitize_text_field($_POST['billing_postal_code']), 0, 10),
            'billing_city' => substr(sanitize_text_field($_POST['billing_city']), 0, 255),
            'email' => substr(sanitize_email($_POST['email']), 0, 255),
            'phone' => substr(sanitize_text_field($_POST['phone']), 0, 50),
        ];

        $wpdb->update('companies', $updated_data, ['uid' => $uid]);
        $updated_data['uid'] = $uid;
        $producer->sendCompanyData($updated_data, 'update');
    }

    // Handle delete
    if (isset($_POST['delete_uid'])) {
        $uid = sanitize_text_field($_POST['delete_uid']);
        $wpdb->delete('companies', ['uid' => $uid]);
        $producer->sendCompanyData(['uid' => $uid], 'delete');
    }

    // Fetch companies
    $companies = $wpdb->get_results($wpdb->prepare("SELECT * FROM companies WHERE owner_id = %s", $owner_uid));
    if (empty($companies)) return '<p>No companies found.</p>';

    $output = '<div class="crf-form"><h3>My Companies</h3><ul style="list-style:none;padding:0;">';

    foreach ($companies as $c) {
        $uid = esc_attr($c->uid);
        $output .= "
        <li style='border:1px solid #ccc;padding:1rem;margin-bottom:1rem;border-radius:6px;'>
            <h2>" . esc_html($c->name) . "</h2>
            <form method='POST' style='display:inline;margin-top:0.5rem;margin-right:0.5rem;'>
                <input type='hidden' name='delete_uid' value='{$uid}'>
                <button class='btn' type='submit'>Delete</button>
            </form>
            <button class='btn btn-primary' type='button' onclick=\"document.getElementById('edit-{$uid}').style.display='block'\">Edit</button>

            <div id='edit-{$uid}' style='display:none;margin-top:1.5rem;'>
                <form method='POST' class='edit-form'>
                    <input type='hidden' name='update_uid' value='{$uid}'>

                    <div class='form-group'>
                        <label class='form-label'>Company Name</label>
                        <input class='form-control' name='company_name' maxlength='255' value='" . esc_attr($c->name) . "' required>
                    </div>
                    <div class='form-group'>
                        <label class='form-label'>Street</label>
                        <input class='form-control' name='company_address' id='company_address_$uid' maxlength='255' value='" . esc_attr($c->street) . "' required>
                    </div>
                    <div class='form-group'>
                        <label class='form-label'>Street Number</label>
                        <input class='form-control' name='street_number' id='street_number_$uid' maxlength='10' value='" . esc_attr($c->number) . "' required>
                    </div>
                    <div class='form-group'>
                        <label class='form-label'>Postal Code</label>
                        <input class='form-control' name='postal_code' id='postal_code_$uid' maxlength='10' value='" . esc_attr($c->postcode) . "' required>
                    </div>
                    <div class='form-group'>
                        <label class='form-label'>City</label>
                        <input class='form-control' name='city' id='city_$uid' maxlength='255' value='" . esc_attr($c->city) . "' required>
                    </div>

                    <fieldset class='form-group'>
                        <legend class='form-label'>Billing Address</legend>
                        <label><input type='radio' name='billing_option_$uid' value='same' id='billing_same_$uid'> Same as company address</label>
                        <label><input type='radio' name='billing_option_$uid' value='custom' id='billing_custom_$uid' checked> Different address</label>
                    </fieldset>

                    <div id='billing_fields_$uid'>
                        <div class='form-group'>
                            <label class='form-label'>Billing Street</label>
                            <input class='form-control' name='billing_address' id='billing_address_$uid' maxlength='255' value='" . esc_attr($c->billing_street) . "' required>
                        </div>
                        <div class='form-group'>
                            <label class='form-label'>Billing Number</label>
                            <input class='form-control' name='billing_street_number' id='billing_street_number_$uid' maxlength='10' value='" . esc_attr($c->billing_number) . "' required>
                        </div>
                        <div class='form-group'>
                            <label class='form-label'>Billing Postal Code</label>
                            <input class='form-control' name='billing_postal_code' id='billing_postal_code_$uid' maxlength='10' value='" . esc_attr($c->billing_postcode) . "' required>
                        </div>
                        <div class='form-group'>
                            <label class='form-label'>Billing City</label>
                            <input class='form-control' name='billing_city' id='billing_city_$uid' maxlength='255' value='" . esc_attr($c->billing_city) . "' required>
                        </div>
                    </div>

                    <div class='form-group'>
                        <label class='form-label'>Email</label>
                        <input class='form-control' name='email' maxlength='255' value='" . esc_attr($c->email) . "' required>
                    </div>
                    <div class='form-group'>
                        <label class='form-label'>Phone</label>
                        <input class='form-control' name='phone' maxlength='50' value='" . esc_attr($c->phone) . "' required>
                    </div>

                    <button type='submit' class='btn btn-primary'>Save</button>
                    <button type='button' class='btn' onclick=\"document.getElementById('edit-$uid').style.display='none'\">Cancel</button>
                </form>
            </div>
        </li>";
    }

    $output .= '</ul></div>';

    // Toggle billing adres bij edit
    $output .= "<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.edit-form').forEach(function(form) {
            const uid = form.querySelector('input[name=\"update_uid\"]').value;
            const same = document.getElementById('billing_same_' + uid);
            const custom = document.getElementById('billing_custom_' + uid);
            const billingGroup = document.getElementById('billing_fields_' + uid);

            const map = {
                'company_address': 'billing_address',
                'street_number': 'billing_street_number',
                'postal_code': 'billing_postal_code',
                'city': 'billing_city'
            };

            function toggle(isSame) {
                for (const [from, to] of Object.entries(map)) {
                    const fromEl = document.getElementById(from + '_' + uid);
                    const toEl = document.getElementById(to + '_' + uid);
                    if (!fromEl || !toEl) continue;

                    if (isSame) {
                        toEl.value = fromEl.value;
                        toEl.readOnly = true;
                        toEl.required = false;
                    } else {
                        toEl.readOnly = false;
                        toEl.required = true;
                    }
                }
                if (billingGroup) billingGroup.classList.toggle('hidden', isSame);
            }

            if (same) same.addEventListener('change', () => toggle(true));
            if (custom) custom.addEventListener('change', () => toggle(false));
            toggle(same && same.checked);
        });
    });
    </script>";

    return $output;
}



function crf_enqueue_inline_style() {
    if (!is_singular()) return;

    global $post;
    if (
        has_shortcode($post->post_content, 'company_register_form') ||
        has_shortcode($post->post_content, 'my_companies')
    ) {
        echo '<style>
        .hidden {
    display: none !important;
}

.crf-form {
    max-width: 700px;
    margin: 0 auto;
}

.form-group {
    font-size: 15px;
    margin-bottom: 1.2rem;
}

.form-label {
    display: block;
    margin-bottom: 0.3rem;
    font-weight: 500;
}

.form-control {
    width: 100%;
    box-sizing: border-box;
    padding: 0.55rem 0.8rem;
    font-size: 15px;
    border: 1px solid #ccc;
    border-radius: 6px;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
    background-color:rgb(255, 255, 255);
    transition: border 0.2s ease-in-out, background 0.2s;
}

.form-control:focus {
    border-color: #888;
    background-color: #fff;
    outline: none;
}

fieldset {
    border: 1px solid #ddd;
    padding: 1rem;
    border-radius: 6px;
    margin-bottom: 1.5rem;
}

legend {
    font-size: 1rem;
    font-weight: 600;
    padding: 0 0.5rem;
}

input[type="radio"] {
    margin-right: 6px;
}

.btn {
    padding: 0.2rem 1.2rem;
    font-size: 14px;
    border: 1px solid #bbb;
    border-radius: 10px;
    cursor: pointer;
    background-color:rgb(255, 255, 255);
    color: #333;
    transition: background-color 0.2s, border-color 0.2s;
}

.btn:hover {
    background-color: #e8e8e8;
    border-color: #999;
}

.btn-primary {
    background-color:rgb(255, 255, 255);
    border-color: var(--wp--preset--color--contrast);
    color:var(--wp--preset--color--contrast);
}

.btn-primary:hover {
    background-color:rgb(240, 240, 240);
    border-color: #999;
    color: #000;
}

.alert {
    padding: 0.8rem 1rem;
    border-radius: 6px;
    margin-bottom: 1rem;
    font-size: 0.95rem;
}

.alert-success {
    background-color: #e6f4ea;
    color: #276738;
    border: 1px solid #cde7d7;
}

.alert-error {
    background-color: #fbeaea;
    color: #7a1c1c;
    border: 1px solid #f5cccc;
}

.crf-form ul li {
    list-style: none;
    margin-bottom: 2rem;
    padding: 1.2rem;
    border: 1px solid #ccc;
    border-radius: 6px;
    background-color:rgb(255, 255, 255);
}

.crf-form form {
    margin-top: 1rem;
}

.crf-form button {
    margin-right: 0.5rem;
    margin-top: 0.3rem;
}

fieldset.form-group label {
    display: inline-block;
    margin-right: 1.5rem;
    font-weight: normal;
}
</style>';

    }
}
add_action('wp_head', 'crf_enqueue_inline_style');
