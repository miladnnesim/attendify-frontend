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
require_once ABSPATH . '/rabbitmq/producer.php';
require_once ABSPATH . '/rabbitmq/produceruserlinkevent.php';


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
        $producer = new Producer();
        $producer->sendUserData($user_id, 'create');
    } catch (Exception $e) {
        error_log("Failed to send user data to RabbitMQ (create): " . $e->getMessage());
    }
}
add_action('um_registration_complete', 'send_user_data_to_rabbitmq_create', 10, 2);
// Hook into user update (voor update)
function send_user_data_to_rabbitmq_update($user_id, $old_data) {
    try {
        $producer = new Producer();
        $producer->sendUserData($user_id, 'update');
    } catch (Exception $e) {
        error_log("Failed to send user data to RabbitMQ (update): " . $e->getMessage());
    }
}
add_action('profile_update', 'send_user_data_to_rabbitmq_update', 10, 2);




// Hook into user deletion (voor delete)
function send_user_delete_to_rabbitmq($user_id) {
    try {
        $producer = new Producer();
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
                $start = date('Ymd\THis\Z', strtotime($event->start_date));
                $end = date('Ymd\THis\Z', strtotime($event->end_date));
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
                sendRegistrationMessage('session', $uid, $session_uid, 'delete');
                wp_redirect(add_query_arg('unregistered', 'session', wp_get_referer()));
            } elseif ($event_uid) {
                sendRegistrationMessage('event', $uid, $event_uid, 'delete');
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
                sendRegistrationMessage('session', $uid, $session_uid); // ✅ goed
                wp_redirect(add_query_arg('registered', 'session', wp_get_referer()));
            } elseif ($event_uid) {
                sendRegistrationMessage('event', $uid, $event_uid); // ✅ goed
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

                // Redirect de gebruiker naar zijn Google Calendar met het event ingevuld
                echo '<div style="padding: 30px; font-family: sans-serif;">';
                echo '<h2>✅ Je bent succesvol geregistreerd voor het event.</h2>';
                echo '<p>Klik hieronder om dit event toe te voegen aan je Google Calendar:</p>';
                echo '<a href="' . esc_url($calendar_url) . '" target="_blank" style="padding: 12px 20px; background-color: #0073aa; color: white; border-radius: 5px; text-decoration: none;">📅 Voeg toe aan Google Calendar</a>';
                echo '<br><br><a href="' . esc_url(home_url('/')) . '">← Terug naar de homepage</a>';
                echo '</div>';
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
    $results = $wpdb->get_results("SELECT ondernemingsNummer, naam FROM companies", ARRAY_A);

    $options = array();
    foreach ($results as $company) {
        $options[$company['ondernemingsNummer']] = $company['naam'];
    }

    return $options;
}

add_shortcode('event_session_list', 'render_event_session_page');

add_action('init', 'twentytwentyfive_register_block_bindings');
