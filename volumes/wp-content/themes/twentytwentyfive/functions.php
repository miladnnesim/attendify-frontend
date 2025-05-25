<?php
/**
 * Twenty Twenty-Five functions and definitions.
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */
require_once ABSPATH . '/rabbitmq/ProducerUser.php';
require_once ABSPATH . '/rabbitmq/RegistrationMessageProducer.php';


// Adds theme support for post formats.
if ( ! function_exists( 'twentytwentyfive_post_format_setup' ) ) :
	/**
	 * Adds theme support for post formats.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_post_format_setup() {
		add_theme_support( 'post-formats', array( 'aside', 'audio', 'chat', 'gallery', 'image', 'link', 'quote', 'status', 'video' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_post_format_setup' );

// Enqueues editor-style.css in the editors.
if ( ! function_exists( 'twentytwentyfive_editor_style' ) ) :
	/**
	 * Enqueues editor-style.css in the editors.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_editor_style() {
		add_editor_style( get_parent_theme_file_uri( 'assets/css/editor-style.css' ) );
	}
endif;
add_action( 'after_setup_theme', 'twentytwentyfive_editor_style' );

// Enqueues style.css on the front.
if ( ! function_exists( 'twentytwentyfive_enqueue_styles' ) ) :
	/**
	 * Enqueues style.css on the front.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_enqueue_styles() {
		wp_enqueue_style(
			'twentytwentyfive-style',
			get_parent_theme_file_uri( 'style.css' ),
			array(),
			wp_get_theme()->get( 'Version' )
		);
	}
endif;
add_action( 'wp_enqueue_scripts', 'twentytwentyfive_enqueue_styles' );

// Registers custom block styles.
if ( ! function_exists( 'twentytwentyfive_block_styles' ) ) :
	/**
	 * Registers custom block styles.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_block_styles() {
		register_block_style(
			'core/list',
			array(
				'name'         => 'checkmark-list',
				'label'        => __( 'Checkmark', 'twentytwentyfive' ),
				'inline_style' => '
				ul.is-style-checkmark-list {
					list-style-type: "\2713";
				}

				ul.is-style-checkmark-list li {
					padding-inline-start: 1ch;
				}',
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_block_styles' );

// Registers pattern categories.
if ( ! function_exists( 'twentytwentyfive_pattern_categories' ) ) :
	/**
	 * Registers pattern categories.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_pattern_categories() {

		register_block_pattern_category(
			'twentytwentyfive_page',
			array(
				'label'       => __( 'Pages', 'twentytwentyfive' ),
				'description' => __( 'A collection of full page layouts.', 'twentytwentyfive' ),
			)
		);

		register_block_pattern_category(
			'twentytwentyfive_post-format',
			array(
				'label'       => __( 'Post formats', 'twentytwentyfive' ),
				'description' => __( 'A collection of post format patterns.', 'twentytwentyfive' ),
			)
		);
	}
endif;
add_action( 'init', 'twentytwentyfive_pattern_categories' );

// Registers block binding sources.
if ( ! function_exists( 'twentytwentyfive_register_block_bindings' ) ) :
	/**
	 * Registers the post format block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return void
	 */
	function twentytwentyfive_register_block_bindings() {
		register_block_bindings_source(
			'twentytwentyfive/format',
			array(
				'label'              => _x( 'Post format name', 'Label for the block binding placeholder in the editor', 'twentytwentyfive' ),
				'get_value_callback' => 'twentytwentyfive_format_binding',
			)
		);
	}
endif;

// Registers block binding callback function for the post format name.
if ( ! function_exists( 'twentytwentyfive_format_binding' ) ) :
	/**
	 * Callback function for the post format name block binding source.
	 *
	 * @since Twenty Twenty-Five 1.0
	 *
	 * @return string|void Post format name, or nothing if the format is 'standard'.
	 */
	function twentytwentyfive_format_binding() {
		$post_format_slug = get_post_format();

		if ( $post_format_slug && 'standard' !== $post_format_slug ) {
			return get_post_format_string( $post_format_slug );
		}
	}
endif;

// Voeg een nieuwe tab toe aan de accountpagina
add_filter('um_account_page_default_tabs_hook', 'add_extra_info_tab', 100);
function add_extra_info_tab($tabs) {
    $tabs[150]['extra_info'] = array(
        'name' => 'Extra Informatie',
        'title' => 'Extra Informatie', // Expliciet title toevoegen
        'icon' => 'um-faicon-info-circle',
        'custom' => true
    );
    return $tabs;
}
add_action('um_before_account_page_load', function () {
	static $once = false;
	if ($once || !is_user_logged_in()) return;

	$once = true;
	UM()->user()->reset();
	UM()->user()->set(get_current_user_id(), true);
});


// Voeg inhoud toe aan de nieuwe tab
add_filter('um_account_content_hook_extra_info', 'um_account_content_hook_extra_info', 10, 2);

function um_account_content_hook_extra_info($output, $tab_id) {
    	

    // Haal het ID van het "Default Registration" formulier op
    $register_form_id = 6; // Pas dit aan als het ID van jouw registratieformulier anders is

    // Haal de velden van het registratieformulier op
    $form_fields = get_post_meta($register_form_id, '_um_custom_fields', true);

    // Debug: Controleer of de velden correct worden opgehaald
    if (empty($form_fields) || !is_array($form_fields)) {
        $output .= '<p>Fout: Geen velden gevonden in het registratieformulier. Controleer het formulier-ID.</p>';
        return $output;
    }

    $fields_displayed = false;
    $fields = array();

    // Haal de huidige gebruiker op
    $user_id = get_current_user_id();
    do_action('profile_update', $user_id, get_userdata($user_id));
    // Loop door de velden en bereid ze voor om te renderen
    foreach ($form_fields as $key => $field) {
        if (isset($field['editable']) && $field['editable'] == 1) {
            $meta_key = isset($field['metakey']) ? $field['metakey'] : $key;

            // Sla standaardvelden over die al door UM worden afgehandeld
            $default_fields = array('user_login', 'user_email', 'user_password', 'first_name', 'last_name', 'description');
            if (in_array($meta_key, $default_fields)) {
                continue;
            }

            $fields_displayed = true;

            // Haal de huidige waarde van het veld op
            $value = get_user_meta($user_id, $meta_key, true);

            // Bereid de veldgegevens voor om te renderen met UM's eigen functie
            $fields[$meta_key] = array(
                'title' => isset($field['title']) ? $field['title'] : ucfirst(str_replace('_', ' ', $meta_key)),
                'metakey' => $meta_key,
                'type' => isset($field['type']) ? $field['type'] : 'text',
                'label' => isset($field['title']) ? $field['title'] : ucfirst(str_replace('_', ' ', $meta_key)),
                'value' => $value,
                'options' => isset($field['options']) ? $field['options'] : array(),
            );
        }
    }

    // Start de output
    ob_start();
    ?>
<div class="um-form">
    <?php
        if (!$fields_displayed) {
            echo '<p>Geen bewerkbare velden gevonden in het registratieformulier.</p>';
        } else {
            // Render de velden met UM's eigen functie
            foreach ($fields as $key => $data) {
                echo UM()->fields()->edit_field($key, $data);
            }
        }
        ?>
</div>
<?php
    $output .= ob_get_clean();
    return $output;
}

// Sla de extra velden op wanneer de gebruiker de accountpagina bijwerkt
add_action('um_account_pre_update_profile', 'save_custom_fields_in_account', 10, 2);
function save_custom_fields_in_account($changes, $user_id) {
    $register_form_id = 6;
    $form_fields = get_post_meta($register_form_id, '_um_custom_fields', true);

    if (!empty($form_fields) && is_array($form_fields)) {
        foreach ($form_fields as $key => $field) {
            if (isset($field['editable']) && $field['editable'] == 1) {
                $meta_key = isset($field['metakey']) ? $field['metakey'] : $key;
                $default_fields = array('user_login', 'user_email', 'user_password', 'first_name', 'last_name', 'description');
                if (in_array($meta_key, $default_fields)) {
                    continue;
                }

                if (isset($_POST[$meta_key])) {
                    $value = $_POST[$meta_key];
                    if (is_array($value)) {
                        $value = array_map('sanitize_text_field', $value);
                    } else {
                        $value = sanitize_text_field($value);
                    }
                    update_user_meta($user_id, $meta_key, $value);
					

                }
            }
        }
    }
	do_action('profile_update', $user_id, $changes);

}

function send_user_data_to_rabbitmq_create($user_id, $args) {
    sleep(2); // wacht 2 seconden
    try {
        $producer = new \App\ProducerUser();
        $producer->sendUserData($user_id, 'create');
    } catch (Exception $e) {
        error_log("Failed to send user data to RabbitMQ (create): " . $e->getMessage());
    }
}
add_action('um_registration_complete', 'send_user_data_to_rabbitmq_create', 10, 2);
// Hook into user update (voor update)
function send_user_data_to_rabbitmq_update($user_id, $old_data) {
    try {
        $producer = new \App\ProducerUser();
        $producer->sendUserData($user_id, 'update');
    } catch (Exception $e) {
        error_log("Failed to send user data to RabbitMQ (update): " . $e->getMessage());
    }
}
add_action('profile_update', 'send_user_data_to_rabbitmq_update', 10, 2);




// Hook into user deletion (voor delete)
function send_user_delete_to_rabbitmq($user_id) {
    try {
        $producer = new \App\ProducerUser();
        $producer->sendUserData($user_id, 'delete');
    } catch (Exception $e) {
        error_log("Failed to send user data to RabbitMQ (delete): " . $e->getMessage());
    }
}
add_action('delete_user', 'send_user_delete_to_rabbitmq', 10, 1);

add_action('init', function() {
    if (false === getenv('MY_API_SHARED_SECRET')) {
        error_log('Shared secret is not set in environment variables!');
    }
});

// Register the REST API endpoint
add_action('rest_api_init', function () {
    register_rest_route('myapiv2', '/set-activation-key', [
        'methods' => 'POST',
        'callback' => 'set_activation_key',
        'permission_callback' => 'verify_shared_secret',
    ]);
});

// Check the shared secret from the request headers
function verify_shared_secret(WP_REST_Request $request) {
    $headers = $request->get_headers();
    $env_secret = getenv('MY_API_SHARED_SECRET');

    if (!$env_secret) {
        return new WP_Error('server_error', 'Shared secret not configured on server', ['status' => 500]);
    }

    if (!isset($headers['x_shared_secret'][0])) {
        return new WP_Error('unauthorized', 'Missing shared secret', ['status' => 401]);
    }

    $provided_secret = $headers['x_shared_secret'][0];
    if (!hash_equals($env_secret, $provided_secret)) {
        return new WP_Error('unauthorized', 'Invalid shared secret', ['status' => 401]);
    }

    return true;
}

// Set the activation key and return the reset link
function set_activation_key(WP_REST_Request $request) {
    // Récupérer les paramètres de la requête
	$activation_key = $request->get_param('activation_key') ?: wp_generate_password(20, false);
    $expiration = time() + (24 * 60 * 60);  // 24h expiry

    // Fix: Use password_hash() for bcrypt (not wp_hash_password)
    $hashed_key = password_hash($activation_key, PASSWORD_BCRYPT);

    // Format: "timestamp:bcrypt_hash"
    $formatted_key = $expiration . ':' . $hashed_key;

    // Retourner la clé formatée
    return rest_ensure_response([
        'hashed_activation_key' => $formatted_key
    ]);
}

function render_clean_event_overview() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to view your event payments and purchases.</p>';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $uid = get_user_meta($user_id, 'uid', true);
    if (empty($uid)) return '<p>No UID found for this user.</p>';

    $payments = $wpdb->get_results($wpdb->prepare("
        SELECT ep.event_id, ep.entrance_fee, ep.entrance_paid, ep.paid_at, e.title
        FROM event_payments ep
        LEFT JOIN wp_events e ON ep.event_id = e.uid
        WHERE ep.uid = %s
        ORDER BY ep.paid_at DESC
    ", $uid));

    $tabs = $wpdb->get_results($wpdb->prepare("
        SELECT ts.id AS tab_id, ts.event_id, ts.timestamp, ts.is_paid, e.title
        FROM tab_sales ts
        LEFT JOIN wp_events e ON ts.event_id = e.uid
        WHERE ts.uid = %s
        ORDER BY ts.timestamp DESC
    ", $uid));

    $tab_ids = array_map(fn($tab) => $tab->tab_id, $tabs);
    $tab_items = [];
    if (!empty($tab_ids)) {
        $query = "SELECT tab_id, item_name, quantity, price FROM tab_items WHERE tab_id IN (" . implode(',', array_fill(0, count($tab_ids), '%d')) . ")";
        $prepared = $wpdb->prepare($query, ...$tab_ids);
        foreach ($wpdb->get_results($prepared) as $item) {
            $tab_items[$item->tab_id][] = $item;
        }
    }

    $events = [];
    foreach ($payments as $p) {
        $eid = $p->event_id;
        $events[$eid]['title'] = $p->title ?: "Unnamed Event (ID: $eid)";
        $events[$eid]['payment'] = $p;
    }
    foreach ($tabs as $tab) {
        $eid = $tab->event_id;
        $events[$eid]['title'] = $tab->title ?: "Unnamed Event (ID: $eid)";
        $events[$eid]['sales'][] = [
            'tab' => $tab,
            'items' => $tab_items[$tab->tab_id] ?? []
        ];
    }

    ob_start();
    echo '<style>
        .event-card {
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-bottom: 20px;
            padding: 16px;
            background: #fff;
        }
        .event-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .event-payment {
            font-size: 14px;
            margin-bottom: 12px;
            color: #333;
        }
        .status-paid { color: #2e7d32; font-weight: 600; }
        .status-unpaid { color: #c62828; font-weight: 600; }
        .toggle-btn {
            display: inline-block;
            margin-bottom: 10px;
            padding: 6px 10px;
            background-color: #f0f0f0;
            border-radius: 4px;
            color: #0073aa;
            cursor: pointer;
            font-size: 14px;
        }
        .sales-content {
            display: none;
            background-color: #fafafa;
            padding: 12px;
            border: 1px solid #eee;
            border-radius: 4px;
            margin-top: 10px;
        }
        .sales-table {
            font-size: 14px;
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        .sales-table th, .sales-table td {
            font-size: 14px;
            border: 1px solid #ddd;
            padding: 8px 12px;
            text-align: left;
        }
        .sales-table th {
            background-color: #f5f5f5;
        }
        .total-amount {
            text-align: right;
            margin-top: 6px;
            font-weight: 600;
        }
    </style>';

    echo '<div class="event-overview">';

    if (empty($events)) {
        echo '<p>No events found for this user.</p>';
    }

    $index = 0;
    foreach ($events as $eid => $data) {
        $title = esc_html($data['title']);
        $indexId = 'event_' . $index++;

        echo "<div class='event-card'>";
        echo "<div class='event-title'>{$title}</div>";

        if (!empty($data['payment'])) {
            $p = $data['payment'];
            $date = $p->paid_at ? date('d/m/Y', strtotime($p->paid_at)) : '-';
            $fee = number_format($p->entrance_fee, 2);
            $status = $p->entrance_paid ? "<span class='status-paid'>Paid</span>" : "<span class='status-unpaid'>Not Paid</span>";
            echo "<div class='event-payment'>Payment: €{$fee} on {$date} – {$status}</div>";
        }

        if (!empty($data['sales'])) {
            echo "<div class='toggle-btn' onclick=\"toggleSales('{$indexId}')\">Show purchases (" . count($data['sales']) . ")</div>";
            echo "<div class='sales-content' id='{$indexId}'>";
            foreach ($data['sales'] as $sale) {
                $tab = $sale['tab'];
                $datum = date('d/m/Y H:i', strtotime($tab->timestamp));
                $tabStatus = $tab->is_paid ? "<span class='status-paid'>Paid</span>" : "<span class='status-unpaid'>Open</span>";
                echo "<div><strong>Sale on:</strong> $datum – $tabStatus</div>";

                if (!empty($sale['items'])) {
                    $total = 0;
                    echo "<table class='sales-table'><thead><tr><th>Item</th><th>Qty</th><th>Unit €</th><th>Total €</th></tr></thead><tbody>";
                    foreach ($sale['items'] as $item) {
                        $line = $item->quantity * $item->price;
                        $total += $line;
                        echo "<tr>
                            <td>" . esc_html($item->item_name) . "</td>
                            <td>$item->quantity</td>
                            <td>" . number_format($item->price, 2) . "</td>
                            <td>" . number_format($line, 2) . "</td>
                        </tr>";
                    }
                    echo "</tbody></table>";
                    echo "<div class='total-amount'>Total: €" . number_format($total, 2) . "</div>";
                }
            }
            echo "</div>";
        }

        echo "</div>";
    }

    echo '</div>';

    echo '<script>
        function toggleSales(id) {
            const el = document.getElementById(id);
            el.style.display = (el.style.display === "none" || el.style.display === "") ? "block" : "none";
        }
    </script>';

    return ob_get_clean();
}
add_shortcode('mijn_betalingen', 'render_clean_event_overview');



function render_event_session_page() {
    $html = '';

    if (isset($_GET['registered'])) {
        $html .= '<div class="notice notice-success"><p>Je bent succesvol geregistreerd voor een ' . esc_html($_GET['registered']) . '.</p></div>';
    } elseif (isset($_GET['unregistered'])) {
        $html .= '<div class="notice notice-info"><p>Je registratie voor de ' . esc_html($_GET['unregistered']) . ' is geannuleerd.</p></div>';
    }

    $is_logged_in = is_user_logged_in();
    $current_user_id = $is_logged_in ? get_current_user_id() : null;
    $current_user_uid = $is_logged_in ? get_user_meta($current_user_id, 'uid', true) : null;

    global $wpdb;
    $events = $wpdb->get_results("SELECT * FROM wp_events ORDER BY start_date ASC");

    if (empty($events)) {
        return "<p>Geen evenementen gevonden.</p>";
    }

    $html .= '<div class="event-list">';
    foreach ($events as $index => $event) {
        $html .= "<div class='event-block'>";
        $html .= "<h2>" . esc_html($event->title) . "</h2>";
        $html .= "<p><strong>Toegang:</strong> €" . esc_html(number_format($event->entrance_fee, 2)) . "</p>";
        $html .= "<p><strong>Locatie:</strong> " . esc_html($event->location) . "</p>";
        $html .= "<p><strong>Datum:</strong> " . esc_html($event->start_date) . " tot " . esc_html($event->end_date) . "</p>";
        $html .= "<p>" . esc_html($event->description) . "</p>";
    
        $is_registered = false;
    
        if ($is_logged_in) {
            $is_registered = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM user_event WHERE user_id = %s AND event_id = %s",
                $current_user_uid,
                $event->uid
            ));
    
            if ($is_registered) {
                $html .= "<p class='registered'>✅ Je bent al geregistreerd voor dit event.</p>";
    
                // Google Calendar knop
                date_default_timezone_set('Europe/Brussels');
                $start = date('Ymd\THis\Z', strtotime($event->start_date . ' ' . $event->start_time));
                $end   = date('Ymd\THis\Z', strtotime($event->end_date   . ' ' . $event->end_time));

                $calendar_url = 'https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query([
                    'text'     => $event->title,
                    'dates'    => $start . '/' . $end,
                    'details'  => $event->description,
                    'location' => $event->location
                ]);
    
                $html .= '<a href="' . esc_url($calendar_url) . '" target="_blank" class="button small" style="margin-bottom: 10px;">📅 Voeg toe aan Google Calendar</a>';
    
                $html .= '<form method="POST" action="/unregisterevent">';
                $html .= '<input type="hidden" name="event_uid" value="' . esc_attr($event->uid) . '">';
                $html .= '<button type="submit" class="button small red">Annuleer registratie</button>';
                $html .= '</form>';
            } else {
                $html .= '<form method="POST" action="/registerevent">';
                $html .= '<input type="hidden" name="event_uid" value="' . esc_attr($event->uid) . '">';
                $html .= '<button type="submit" class="button">Registreer voor event</button>';
                $html .= '</form>';
            }
        } else {
            $html .= '<button class="button disabled" disabled>Log in om te registreren</button>';
        }

    
        

    
    
        // Sessies
        $sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM wp_sessions WHERE event_uid = %s ORDER BY date ASC", $event->uid));
        if ($sessions) {
            $html .= "<button class='toggle-button' onclick='toggleSessions(\"sessions_$index\")'>Toon/Verberg Sessies</button>";
            $html .= "<div class='session-list' id='sessions_$index' style='display:none;'><ul>";

            foreach ($sessions as $session) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM user_session WHERE session_id = %s",
                    $session->uid
                ));

                $html .= "<li>";
                $html .= "<strong>" . esc_html($session->title) . "</strong><br>";
                $html .= "<em>" . esc_html($session->date) . " (" . esc_html($session->start_time) . " - " . esc_html($session->end_time) . ")</em><br>";
                $html .= "<p>" . esc_html($session->description) . "</p>";
                $html .= "<p><strong>Locatie:</strong> " . esc_html($session->location) . "</p>";
                $html .= "<p><strong>Spreker:</strong> " . esc_html($session->speaker_name) . "<br><em>" . esc_html($session->speaker_bio) . "</em></p>";
                $html .= "<p><strong>Aantal deelnemers:</strong> " . esc_html($count) . " / " . esc_html($session->max_attendees) . "</p>";

                if ($is_logged_in) {
                    $is_registered_session = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM user_session WHERE user_id = %s AND session_id = %s",
                        $current_user_uid,
                        $session->uid
                    ));

                    if ($is_registered_session) {
                        $html .= "<p class='registered'>✅ Je bent al geregistreerd voor deze sessie.</p>";
                        $html .= '<form method="POST" action="/unregisterevent">';
                        $html .= '<input type="hidden" name="session_uid" value="' . esc_attr($session->uid) . '">';
                        $html .= '<button type="submit" class="button small red">Annuleer registratie</button>';
                        $html .= '</form>';
                        date_default_timezone_set('Europe/Brussels');
                        $start = date('Ymd\THis\Z', strtotime($session->date . ' ' . $session->start_time));
                        $end   = date('Ymd\THis\Z', strtotime($session->date . ' ' . $session->end_time));
                        $calendar_url = 'https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query([
                            'text'     => $session->title,
                            'dates'    => $start . '/' . $end,
                            'details'  => $session->description,
                            'location' => $session->location
                        ]);

                        $html .= '<a href="' . esc_url($calendar_url) . '" target="_blank" class="button small" style="margin-bottom: 10px;">📅 Voeg toe aan Google Calendar</a>';

                    } elseif ($is_registered) {
                        // Alleen registreren voor sessie als je voor event geregistreerd bent
                        $html .= '<form method="POST" action="/registerevent">';
                        $html .= '<input type="hidden" name="session_uid" value="' . esc_attr($session->uid) . '">';
                        $html .= '<button type="submit" class="button small">Registreer voor sessie</button>';
                        $html .= '</form>';
                    } else {
                        // Niet mogelijk sessie te registreren zonder event
                        $html .= '<button class="button small disabled" disabled>Registreer eerst voor het event</button>';
                    }
                    
                } else {
                    $html .= '<button class="button small disabled" disabled>Log in om te registreren</button>';
                }

                $html .= "</li>";
            }

            $html .= "</ul></div>";
        }

        $html .= "</div><hr>";
    }

    $html .= "</div>";
    $html .= <<<JS
    <script>
    function toggleSessions(id) {
        const element = document.getElementById(id);
        element.style.display = element.style.display === "none" ? "block" : "none";
    }
    </script>
    JS;

    return $html;
}


function custom_button_styles() {
            echo '<style>
            .button {
        display: inline-block;
        padding: 10px 18px;
        font-size: 15px;
        font-weight: 600;
        text-align: center;
        text-decoration: none;
        background-color: #0073aa;
        color: #fff;
        border: none;
        border-radius: 4px;
        transition: background-color 0.3s ease;
        cursor: pointer;
        margin-top: 8px;
        }

        .button:hover {
        background-color: #005a8c;
        }

        .button.small {
        padding: 8px 14px;
        font-size: 14px;
        }

        .button.disabled {
        background-color: #ccc;
        cursor: not-allowed;
        }
        .toggle-button {
        display: inline-block;
        margin-top: 10px;
        margin-bottom: 10px;
        padding: 8px 14px;
        background-color: #f0f0f0;
        border: 1px solid #ccc;
        border-radius: 4px;
        cursor: pointer;
        }
        .toggle-button:hover {
        background-color: #e0e0e0;
        }
        .button.red {
        background-color: #c0392b;
        }
        .button.red:hover {
        background-color: #922b21;
        }

            </style>';
}
add_action('wp_head', 'custom_button_styles');


function get_user_uid($user_id) {
    return get_user_meta($user_id, 'uid', true);
}

add_action('init', function () {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], '/unregisterevent') !== false) {
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn om je registratie te annuleren.');
        }

        $wordpress_user_id = get_current_user_id();
        $uid = get_user_meta($wordpress_user_id, 'uid', true);

        if (empty($uid)) {
            wp_die('UID niet gevonden voor de huidige gebruiker.');
        }

        $event_uid = $_POST['event_uid'] ?? null;
        $session_uid = $_POST['session_uid'] ?? null;

        try {
            if ($session_uid) {
                \App\sendRegistrationMessage('session', $uid, $session_uid, 'unregister');
                wp_redirect(add_query_arg('unregistered', 'session', wp_get_referer()));
            } elseif ($event_uid) {
                \App\sendRegistrationMessage('event', $uid, $event_uid, 'unregister');
                wp_redirect(add_query_arg('unregistered', 'event', wp_get_referer()));
            } else {
                wp_die('Ongeldige annuleringsgegevens.');
            }
            exit;
        } catch (Exception $e) {
            error_log("Annulering fout: " . $e->getMessage());
            wp_die('Annulering mislukt.');
        }
    }
});

add_action('init', function () {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['REQUEST_URI'], '/registerevent') !== false) {
        if (!is_user_logged_in()) {
            wp_die('Je moet ingelogd zijn om te registreren.');
        }

        $wordpress_user_id = get_current_user_id();
        $uid = get_user_uid($wordpress_user_id);

        if (empty($uid)) {
            wp_die('UID niet gevonden voor de huidige gebruiker.');
        }

        $event_uid = $_POST['event_uid'] ?? null;
        $session_uid = $_POST['session_uid'] ?? null;

        try {
            if ($session_uid) {
                \App\sendRegistrationMessage('session', $uid, $session_uid); // ✅ goed
                wp_redirect(add_query_arg('registered', 'session', wp_get_referer()));
            } elseif ($event_uid) {
                \App\sendRegistrationMessage('event', $uid, $event_uid); // ✅ goed
                // Tijdzone correct instellen
                date_default_timezone_set('Europe/Brussels');




                global $wpdb;
                $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_events WHERE uid = %s", $event_uid));
                if (!$event) wp_die('Event niet gevonden.');

                // Start & eindtijd formatteren naar RFC5545 (UTC)
                $start = date('Ymd\THis\Z', strtotime($event->start_date));
                $end = date('Ymd\THis\Z', strtotime($event->end_date));

                // Google Calendar link genereren
                $calendar_url = 'https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query([
                    'text'     => $event->title,
                    'dates'    => $start . '/' . $end,
                    'details'  => $event->description,
                    'location' => $event->location
                ]);
                wp_redirect(wp_get_referer());
                exit;

            }
             else {
                wp_die('Ongeldige registratiegegevens.');
            }
            exit;
        } catch (Exception $e) {
            error_log("Producer fout: " . $e->getMessage());
            wp_die('Registratie mislukt.');
        }
    }
});
function um_company_choices_callback() {
    global $wpdb;
    $results = $wpdb->get_results("SELECT uid, name FROM companies", ARRAY_A);

    $options = array();
    foreach ($results as $company) {
        $options[$company['uid']] = $company['name'];
    }

    return $options;
}

add_shortcode('event_session_list', 'render_event_session_page');

function activate_account_shortcode() {
    if (isset($_GET['key']) && isset($_GET['login'])) {
        $key = sanitize_text_field($_GET['key']);
        $login = sanitize_text_field($_GET['login']);

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
        .alert-success {
            background-color: #e6f4ea;
            color: #276738;
            border: 1px solid #cde7d7;
            padding: 0.8rem 1rem;
            border-radius: 6px;
            margin-top: 1rem;
        }
        .alert-error {
            background-color: #fbeaea;
            color: #7a1c1c;
            border: 1px solid #f5cccc;
            padding: 0.8rem 1rem;
            border-radius: 6px;
            margin-top: 1rem;
        }
        </style>

        <div class="crf-form">
            <h2>Choose a new password for <strong><?php echo esc_html($login); ?></strong></h2>
            <form method="post">
                <input type="hidden" name="rp_key" value="<?php echo esc_attr($key); ?>">
                <input type="hidden" name="rp_login" value="<?php echo esc_attr($login); ?>">

                <div class="form-group">
                    <label class="form-label" for="new_pass">New password</label>
                    <input class="form-control" type="password" name="new_pass" id="new_pass" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="new_pass_repeat">Repeat password</label>
                    <input class="form-control" type="password" name="new_pass_repeat" id="new_pass_repeat" required>
                </div>
                <button type="submit" name="set_password" class="btn btn-primary">Set Password</button>
            </form>

        <?php
        if (isset($_POST['set_password'])) {
            $user = check_password_reset_key($key, $login);
            if (!is_wp_error($user)) {
                $new_pass = $_POST['new_pass'];
                $repeat = $_POST['new_pass_repeat'];

                if ($new_pass === $repeat) {
                    reset_password($user, $new_pass);
                    echo '<div class="alert-success">Password successfully set. <a href="' . wp_login_url() . '">Log in</a></div>';
                } else {
                    echo '<div class="alert-error">Passwords do not match.</div>';
                }
            } else {
                echo '<div class="alert-error">Invalid or expired activation link.</div>';
            }
        }
        echo '</div>'; // .crf-form

        return ob_get_clean();
    } else {
        return '<div class="alert-error">No activation information found in the URL.</div>';
    }
}
add_shortcode('activate_account', 'activate_account_shortcode');


add_action('init', function () {
    global $wpdb;

    // Ophalen van laatst verwerkte user ID
    $last_checked_id = (int) get_option('_auto_refresh_last_user_id', 0);

    // Zoek users met hogere ID
    $new_users = $wpdb->get_results($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT 5",
        $last_checked_id
    ));

    if (empty($new_users)) {
        return;
    }

    foreach ($new_users as $user) {
        $result = wp_update_user(['ID' => $user->ID]);

        if (!is_wp_error($result)) {
            update_option('_auto_refresh_last_user_id', $user->ID);
            error_log("[AutoRefreshNewUsers] Gebruiker {$user->ID} succesvol vernieuwd.");
        } else {
            error_log("[AutoRefreshNewUsers ERROR] Gebruiker {$user->ID} niet geüpdatet: " . $result->get_error_message());
        }
    }
});

function render_event_grid() {
    global $wpdb;

    // --- FILTERS ophalen uit $_GET ---
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $price_filter = isset($_GET['price_range']) ? $_GET['price_range'] : [];
    $date_filter = isset($_GET['date_filter']) ? sanitize_text_field($_GET['date_filter']) : '';

    // --- BASISQUERY ---
    $query = "SELECT * FROM wp_events WHERE 1=1";
    $params = [];

    if ($search) {
        $query .= " AND title LIKE %s";
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }

    if (!empty($price_filter)) {
        $price_conditions = [];
        foreach ($price_filter as $range) {
            if ($range === '5-10') {
                $price_conditions[] = "(entrance_fee >= 5 AND entrance_fee <= 10)";
            } elseif ($range === '10-15') {
                $price_conditions[] = "(entrance_fee > 10 AND entrance_fee <= 15)";
            } elseif ($range === '15-20') {
                $price_conditions[] = "(entrance_fee > 15 AND entrance_fee <= 20)";
            } elseif ($range === '20+') {
                $price_conditions[] = "(entrance_fee > 20)";
            }
        }
        if (!empty($price_conditions)) {
            $query .= " AND (" . implode(" OR ", $price_conditions) . ")";
        }
    }

    if ($date_filter === 'today') {
        $query .= " AND start_date = CURDATE()";
    } elseif ($date_filter === 'week') {
        $query .= " AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    } elseif ($date_filter === 'month') {
        $query .= " AND start_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 MONTH)";
    }

    $query .= " ORDER BY start_date ASC";
    $events = $params ? $wpdb->get_results($wpdb->prepare($query, ...$params)) : $wpdb->get_results($query);

    ob_start();
    ?>
        <style>
        /* 1. Overall Container Layout */
        .event-grid-container {
            display: flex;
            flex-wrap: wrap;
            width: 100%;
            padding: 32px 48px; /* Add left/right breathing room */
            box-sizing: border-box;
            padding: 0px;
            gap: 32px;
        }

        /* 2. Sidebar (filters) */
        .event-sidebar {
            flex: 0 0 260px;
            background: #fff;
            border: 1px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            position: sticky;
            top: 20px;
            align-self: flex-start;
            box-sizing: border-box;
        }

        .filter-block {
            margin-bottom: 28px;
            padding-right: 5px;
        }

        .filter-block h4 {
            font-size: 15px;
            margin-bottom: 10px;
            border-bottom: 1px solid #eee;
            padding-bottom: 4px;
        }

        .filter-block label {
            display: block;
            font-size: 14px;
            margin-bottom: 6px;
        }

        .event-sidebar input[type="text"] {
            display: block;
            width: 100%;
            box-sizing: border-box;
            max-width: 100%;
            padding: 8px;
            font-size: 14px;
            margin-bottom: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }


        /* 3. Main area */
        .event-main {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .event-main h2 {
            font-size: 22px;
            margin-bottom: 16px;
        }

        /* 4. Grid of event cards */
        .event-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }

        /* 5. Event card */
        .event-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 16px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: box-shadow 0.2s ease;
        }

        .event-card:hover {
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
        }

        .event-card h3 {
            font-size: 17px;
            margin-top: 0;
            margin-bottom: 8px;
        }

        .event-card .meta {
            font-size: 14px;
            color: #555;
            margin-bottom: 6px;
        }

        .event-card .price {
            font-weight: bold;
            margin: 10px 0;
        }

        .card-footer {
            margin-top: auto;
            padding-top: 12px;
        }

        .event-card .button {
            display: inline-block;
            padding: 8px 12px;
            background-color: #0073aa;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }

        .event-card .button:hover {
            background-color: #005a8c;
        }

        /* 6. Mobile responsive */
        @media (max-width: 768px) {
            .event-grid-container {
                flex-direction: column;
                padding: 16px;
            }

            .event-sidebar {
                width: 100%;
                position: static;
                margin-bottom: 20px;
            }
        }
        </style>



    <form method="get">
    <div class="event-grid-container">
        <div class="event-sidebar">
            <div class="filter-block">
                <h4>Zoek een event</h4>
                <input type="text" name="search" placeholder="Titel..." value="<?php echo esc_attr($search); ?>" onchange="this.form.submit()">
            </div>
            <div class="filter-block">
                <h4>Prijsrange</h4>
                <?php
                $ranges = [ '5-10' => '€5–€10', '10-15' => '€10–€15', '15-20' => '€15–€20', '20+' => '€20+' ];
                foreach ($ranges as $key => $label) {
                    $checked = in_array($key, (array)$price_filter) ? 'checked' : '';
                    echo "<label><input type='checkbox' name='price_range[]' value='$key' $checked onchange='this.form.submit()'> $label</label>";
                }
                ?>
            </div>
            <div class="filter-block">
                <h4>Datum</h4>
                <?php
                $date_options = [
                    '' => 'Alle',
                    'today' => 'Vandaag',
                    'week' => 'Deze week',
                    'month' => 'Deze maand'
                ];
                foreach ($date_options as $key => $label) {
                    $checked = ($date_filter === $key) ? 'checked' : '';
                    echo "<label><input type='radio' name='date_filter' value='$key' $checked onchange='this.form.submit()'> $label</label>";
                }
                ?>
            </div>
        </div>

        <div class="event-main">
            <div class="event-grid">
            <?php
            if (empty($events)) {
                echo '<p>Geen evenementen gevonden.</p>';
            } else {
                foreach ($events as $event) {
                    $title = esc_html($event->title);
                    $location = esc_html($event->location);
                    $start = esc_html($event->start_date);
                    $end = esc_html($event->end_date);
                    $price = number_format($event->entrance_fee, 2);
                    $link = '/event-detail?uid=' . esc_attr($event->uid);

                    echo "<div class='event-card'>";
                    echo "<h3>$title</h3>";
                    echo "<div class='meta'>$start tot $end</div>";
                    echo "<div class='meta'>$location</div>";
                    echo "<div class='price'>€$price</div>";
                    echo "<div class='card-footer'><a href='$link' class='button'>Meer info</a></div>";
                    echo "</div>";
                }
            }
            ?>
            </div>
        </div>
    </div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('event_grid', 'render_event_grid');

function render_event_detail_viewer() {
    if (!isset($_GET['uid'])) return '<p>Geen event geselecteerd.</p>';

    global $wpdb;
    $uid = sanitize_text_field($_GET['uid']);
    $event = $wpdb->get_row($wpdb->prepare("SELECT * FROM wp_events WHERE uid = %s", $uid));
    if (!$event) return '<p>Event niet gevonden.</p>';

    $is_logged_in = is_user_logged_in();
    $user_id = $is_logged_in ? get_current_user_id() : null;
    $user_uid = $is_logged_in ? get_user_meta($user_id, 'uid', true) : null;
    $is_registered = $is_logged_in ? $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM user_event WHERE user_id = %s AND event_id = %s",
        $user_uid,
        $uid
    )) : false;

    ob_start();
    ?>

    <style>
    .event-detail-container {
        width: 100%;
        margin: 40px auto;
        padding: 24px;
        background: #fff;
        border-radius: 8px;
        border: 1px solid #ddd;
        font-family: system-ui, sans-serif;
    }
    .event-detail-container h2 {
        margin-top: 0;
    }
    .event-detail-container p {
        margin: 8px 0;
    }
    .event-meta p {
        margin: 4px 0;
        font-size: 15px;
    }
    .button {
        display: inline-block;
        margin: 10px 10px 0 0;
        padding: 8px 12px;
        background: #0073aa;
        color: white;
        border-radius: 4px;
        text-decoration: none;
        border: none;
        cursor: pointer;
        font-size: 14px;
    }
    .button:hover {
        background: #005a8c;
    }
    .button.red {
        background: #d63638;
    }
    .button.red:hover {
        background: #a90002;
    }
    .session-block {
        background: #f9f9f9;
        padding: 16px;
        margin-top: 20px;
        border-left: 4px solid #0073aa;
        border-radius: 4px;
    }
    .back-link {
        margin-bottom: 16px;
        display: inline-block;
        font-size: 14px;
        text-decoration: none;
        color: #0073aa;
    }
    .back-link:hover {
        text-decoration: underline;
    }
    </style>

    <div class="event-detail-container">
        <a href="<?php echo esc_url(wp_get_referer())  ?>" class="back-link">← Terug naar eventlijst</a>

        <h2><?php echo esc_html($event->title); ?></h2>

        <div class="event-meta">
<p><strong>Datum & Tijd:</strong>
  <?php
    echo esc_html( date_i18n( 'd/m/Y H:i', strtotime("$event->start_date {$event->start_time}") ) );
    echo ' – ';
    echo esc_html( date_i18n( 'd/m/Y H:i', strtotime("$event->end_date {$event->end_time}") ) );
  ?>
</p>            <p><strong>Locatie:</strong> <?php echo esc_html($event->location); ?></p>
            
            <p><strong>Toegang:</strong> €<?php echo number_format($event->entrance_fee, 2); ?></p>
        </div>

        <p><?php echo esc_html($event->description); ?></p>

        <?php if ($is_logged_in): ?>
            <?php if ($is_registered): ?>
                <p>Je bent al geregistreerd voor dit event.</p>
                <a href="<?php echo esc_url('https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query([
                    'text' => $event->title,
'dates' => date('Ymd\THis', strtotime("$event->start_date {$event->start_time}")) . '/' .
          date('Ymd\THis', strtotime("$event->end_date {$event->end_time}")),
                    'details' => $event->description,
                    'location' => $event->location
                ])); ?>" target="_blank" class="button">Voeg toe aan Google Calendar</a>

                <form method="POST" action="/unregisterevent">
                    <input type="hidden" name="event_uid" value="<?php echo esc_attr($uid); ?>">
                    <button type="submit" class="button red">Annuleer registratie</button>
                </form>
            <?php else: ?>
                <form method="POST" action="/registerevent">
                    <input type="hidden" name="event_uid" value="<?php echo esc_attr($uid); ?>">
                    <button type="submit" class="button">Registreer voor event</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p><em>Log in om je te registreren.</em></p>
        <?php endif; ?>

        <h3>Sessies</h3>

        <?php
        $sessions = $wpdb->get_results($wpdb->prepare("SELECT * FROM wp_sessions WHERE event_uid = %s ORDER BY date ASC", $uid));
        foreach ($sessions as $session):
            $is_session_registered = $is_logged_in ? $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM user_session WHERE user_id = %s AND session_id = %s",
                $user_uid,
                $session->uid
            )) : false;

            $session_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM user_session WHERE session_id = %s", $session->uid));
        ?>
            <div class="session-block">
                <strong><?php echo esc_html($session->title); ?></strong>
                <p><strong>Datum:</strong> <?php echo esc_html($session->date); ?> <?php echo esc_html($session->start_time); ?>–<?php echo esc_html($session->end_time); ?></p>
                <p><strong>Locatie:</strong> <?php echo esc_html($session->location); ?></p>
                <p><strong>Spreker:</strong> <?php echo esc_html($session->speaker_name); ?></p>
                <p><em><?php echo esc_html($session->speaker_bio); ?></em></p>
                <p><?php echo esc_html($session->description); ?></p>
                <p><strong>Aantal deelnemers:</strong> <?php echo esc_html($session_count); ?> / <?php echo esc_html($session->max_attendees); ?></p>

                <?php if (!$is_logged_in): ?>
                    <p><em>Log in om je te registreren voor sessies.</em></p>
                <?php elseif (!$is_registered): ?>
                    <p><em>Registreer eerst voor het event om sessies te kunnen bijwonen.</em></p>
                <?php elseif ($is_session_registered): ?>
                    <form method="POST" action="/unregisterevent">
                        <input type="hidden" name="session_uid" value="<?php echo esc_attr($session->uid); ?>">
                        <button type="submit" class="button red">Annuleer registratie</button>
                    </form>
                    <a href="<?php echo esc_url('https://calendar.google.com/calendar/u/0/r/eventedit?' . http_build_query([
                        'text' => $session->title,
                        'dates' => date('Ymd\THis', strtotime($session->date . ' ' . $session->start_time)) . '/' .
                                date('Ymd\THis', strtotime($session->date . ' ' . $session->end_time)),
                        'details' => $session->description,
                        'location' => $session->location,
                        'ctz' => 'Europe/Brussels'
                    ])); ?>" target="_blank" class="button">Voeg toe aan Google Calendar</a>
                <?php else: ?>
                    <form method="POST" action="/registerevent">
                        <input type="hidden" name="session_uid" value="<?php echo esc_attr($session->uid); ?>">
                        <button type="submit" class="button">Registreer voor sessie</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php
    return ob_get_clean();
}
add_shortcode('event_detail_viewer', 'render_event_detail_viewer');

add_action('after_setup_theme', function () {
    remove_theme_support('core-block-patterns');
});

function render_attendify_homepage() {
    ob_start();
    ?>
    <style>
        .homepage-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end; /* descend le contenu */
            background: url('<?php echo get_stylesheet_directory_uri(); ?>/backdrop.png') no-repeat center top;
            background-size: 100% auto;
            width: 100%;
            min-height: 120vh;
            padding-bottom: 80px; /* espace depuis le bas */
            box-sizing: border-box;
        }

        .homepage-wrapper a.button {
            background-color: #0073aa;
            color: white;
            padding: 16px 48px;
            text-decoration: none;
            border-radius: 6px;
            font-size: 35px;
        }

        .homepage-wrapper a.button:hover {
            background-color: #005a8c;
        }
        .thisone {
            background-color: #0073aa;
            color: white;
            padding: 16px 150px;
            text-decoration: none;
            border-radius: 46px;
            font-size: 25px;
            font-weight: 900;
        }
    </style>

    <div class="homepage-wrapper">
        <a href="/event-en-session" class="thisone">All events</a>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('homepage', 'render_attendify_homepage');


add_action('init', 'twentytwentyfive_register_block_bindings');