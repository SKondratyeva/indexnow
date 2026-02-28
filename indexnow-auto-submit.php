<?php
/**
 * Plugin Name:  IndexNow Auto Submit
 * Description:  Automatically submits URLs to IndexNow when content is published or updated for faster search engine indexing.
 * Version:      1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  indexnow-auto-submit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INDEXNOW_AS_VERSION',    '1.0.0' );
define( 'INDEXNOW_AS_OPTION_KEY', 'indexnow_as_settings' );
define( 'INDEXNOW_AS_LOG_OPTION', 'indexnow_as_submission_log' );

// -------------------------------------------------------------------------
// Activation / Deactivation
// -------------------------------------------------------------------------

register_activation_hook( __FILE__, 'indexnow_as_activate' );
function indexnow_as_activate() {
	$settings = get_option( INDEXNOW_AS_OPTION_KEY, array() );

	if ( empty( $settings['api_key'] ) ) {
		$settings['api_key'] = wp_generate_password( 32, false );
	}
	if ( empty( $settings['endpoint'] ) ) {
		$settings['endpoint'] = 'https://api.indexnow.org/indexnow';
	}
	if ( ! isset( $settings['post_types'] ) ) {
		$settings['post_types'] = array( 'post', 'page' );
	}
	if ( ! isset( $settings['auto_submit'] ) ) {
		$settings['auto_submit'] = true;
	}

	update_option( INDEXNOW_AS_OPTION_KEY, $settings );

	// Register rewrite rule then flush so the key file is immediately accessible.
	indexnow_as_add_rewrite_rules();
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, function() {
	flush_rewrite_rules();
} );

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

/**
 * Returns merged settings, falling back to the INDEXNOW_API_KEY constant when
 * no key has been stored in the database.
 */
function indexnow_as_get_settings(): array {
	$defaults = array(
		'api_key'     => '',
		'endpoint'    => 'https://api.indexnow.org/indexnow',
		'post_types'  => array( 'post', 'page' ),
		'auto_submit' => true,
	);

	$settings = get_option( INDEXNOW_AS_OPTION_KEY, array() );

	if ( empty( $settings['api_key'] ) && defined( 'INDEXNOW_API_KEY' ) ) {
		$settings['api_key'] = INDEXNOW_API_KEY;
	}

	return wp_parse_args( $settings, $defaults );
}

/** Appends a submission record to the rolling 50-entry log. */
function indexnow_as_log( array $urls, int $status_code, string $message ): void {
	$log = get_option( INDEXNOW_AS_LOG_OPTION, array() );
	array_unshift( $log, array(
		'timestamp'   => current_time( 'mysql' ),
		'urls'        => $urls,
		'status_code' => $status_code,
		'message'     => $message,
	) );
	update_option( INDEXNOW_AS_LOG_OPTION, array_slice( $log, 0, 50 ) );
}

// -------------------------------------------------------------------------
// Key-file endpoint  —  serves  /{api-key}.txt
// -------------------------------------------------------------------------

add_action( 'init', 'indexnow_as_add_rewrite_rules' );
function indexnow_as_add_rewrite_rules(): void {
	add_rewrite_rule(
		'^([a-zA-Z0-9\-]{16,64})\.txt$',
		'index.php?indexnow_key_file=$matches[1]',
		'top'
	);
}

add_filter( 'query_vars', function( array $vars ): array {
	$vars[] = 'indexnow_key_file';
	return $vars;
} );

add_action( 'template_redirect', 'indexnow_as_serve_key_file' );
function indexnow_as_serve_key_file(): void {
	$key_request = get_query_var( 'indexnow_key_file' );
	if ( empty( $key_request ) ) {
		return;
	}

	$api_key = indexnow_as_get_settings()['api_key'];

	if ( empty( $api_key ) || $key_request !== $api_key ) {
		status_header( 404 );
		exit;
	}

	header( 'Content-Type: text/plain; charset=UTF-8' );
	status_header( 200 );
	echo esc_html( $api_key );
	exit;
}

// -------------------------------------------------------------------------
// Core submission function
// -------------------------------------------------------------------------

/**
 * Submits an array of URLs to IndexNow.
 *
 * @param  string[] $urls Fully-qualified URLs to submit.
 * @return array|WP_Error  Result array on success, WP_Error on failure.
 */
function indexnow_as_submit_urls( array $urls ) {
	$urls = array_values( array_unique( array_filter( $urls ) ) );

	if ( empty( $urls ) ) {
		return new WP_Error( 'empty_urls', __( 'No URLs provided.', 'indexnow-auto-submit' ) );
	}

	$settings = indexnow_as_get_settings();
	$api_key  = $settings['api_key'];

	if ( empty( $api_key ) ) {
		return new WP_Error( 'no_api_key', __( 'IndexNow API key is not configured.', 'indexnow-auto-submit' ) );
	}

	$host    = wp_parse_url( get_site_url(), PHP_URL_HOST );
	$key_url = trailingslashit( get_site_url() ) . $api_key . '.txt';

	$payload = array(
		'host'        => $host,
		'key'         => $api_key,
		'keyLocation' => $key_url,
		'urlList'     => $urls,
	);

	$response = wp_remote_post(
		$settings['endpoint'],
		array(
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		)
	);

	if ( is_wp_error( $response ) ) {
		indexnow_as_log( $urls, 0, $response->get_error_message() );
		return $response;
	}

	$status_code = (int) wp_remote_retrieve_response_code( $response );
	$success     = in_array( $status_code, array( 200, 202 ), true );
	/* translators: %d: number of URLs */
	$message = $success
		? sprintf( _n( 'Successfully submitted %d URL.', 'Successfully submitted %d URLs.', count( $urls ), 'indexnow-auto-submit' ), count( $urls ) )
		: sprintf( __( 'Submission failed with HTTP %d.', 'indexnow-auto-submit' ), $status_code );

	indexnow_as_log( $urls, $status_code, $message );

	if ( ! $success ) {
		return new WP_Error( 'submission_failed', $message, array( 'status' => $status_code ) );
	}

	return array(
		'success'     => true,
		'status_code' => $status_code,
		'message'     => $message,
		'urls'        => $urls,
	);
}

// -------------------------------------------------------------------------
// REST API
// -------------------------------------------------------------------------

add_action( 'rest_api_init', 'indexnow_as_register_rest_routes' );
function indexnow_as_register_rest_routes(): void {

	// POST /wp-json/indexnow/v1/submit
	register_rest_route( 'indexnow/v1', '/submit', array(
		'methods'             => 'POST',
		'callback'            => 'indexnow_as_rest_submit',
		'permission_callback' => function() {
			return current_user_can( 'edit_posts' );
		},
		'args' => array(
			'urls' => array(
				'required'          => true,
				'type'              => 'array',
				'items'             => array( 'type' => 'string', 'format' => 'uri' ),
				'sanitize_callback' => function( $urls ) {
					return array_map( 'esc_url_raw', (array) $urls );
				},
			),
		),
	) );

	// GET /wp-json/indexnow/v1/settings
	// POST /wp-json/indexnow/v1/settings
	register_rest_route( 'indexnow/v1', '/settings', array(
		array(
			'methods'             => 'GET',
			'callback'            => 'indexnow_as_rest_get_settings',
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		),
		array(
			'methods'             => 'POST',
			'callback'            => 'indexnow_as_rest_update_settings',
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		),
	) );

	// GET /wp-json/indexnow/v1/log
	register_rest_route( 'indexnow/v1', '/log', array(
		'methods'             => 'GET',
		'callback'            => function() {
			return new WP_REST_Response( get_option( INDEXNOW_AS_LOG_OPTION, array() ), 200 );
		},
		'permission_callback' => function() {
			return current_user_can( 'manage_options' );
		},
	) );
}

function indexnow_as_rest_submit( WP_REST_Request $request ): WP_REST_Response {
	$result = indexnow_as_submit_urls( $request->get_param( 'urls' ) );

	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		$code = isset( $data['status'] ) ? $data['status'] : 500;
		return new WP_REST_Response( array( 'success' => false, 'error' => $result->get_error_message() ), $code );
	}

	return new WP_REST_Response( $result, 200 );
}

function indexnow_as_rest_get_settings( WP_REST_Request $request ): WP_REST_Response {
	$settings = indexnow_as_get_settings();
	// Mask key — only expose the first 4 characters via REST.
	if ( ! empty( $settings['api_key'] ) ) {
		$settings['api_key'] = substr( $settings['api_key'], 0, 4 ) . str_repeat( '*', max( 0, strlen( $settings['api_key'] ) - 4 ) );
	}
	return new WP_REST_Response( $settings, 200 );
}

function indexnow_as_rest_update_settings( WP_REST_Request $request ): WP_REST_Response {
	$params   = $request->get_json_params();
	$settings = indexnow_as_get_settings();

	$allowed_endpoints = array(
		'https://api.indexnow.org/indexnow',
		'https://www.bing.com/indexnow',
		'https://yandex.com/indexnow',
	);

	if ( isset( $params['api_key'] ) ) {
		$settings['api_key'] = sanitize_text_field( $params['api_key'] );
	}
	if ( isset( $params['endpoint'] ) && in_array( $params['endpoint'], $allowed_endpoints, true ) ) {
		$settings['endpoint'] = $params['endpoint'];
	}
	if ( isset( $params['post_types'] ) && is_array( $params['post_types'] ) ) {
		$valid = array_keys( get_post_types( array( 'public' => true ) ) );
		$settings['post_types'] = array_intersect( (array) $params['post_types'], $valid );
	}
	if ( isset( $params['auto_submit'] ) ) {
		$settings['auto_submit'] = (bool) $params['auto_submit'];
	}

	update_option( INDEXNOW_AS_OPTION_KEY, $settings );
	return new WP_REST_Response( array( 'success' => true, 'message' => __( 'Settings updated.', 'indexnow-auto-submit' ) ), 200 );
}

// -------------------------------------------------------------------------
// Auto-submit hooks
// -------------------------------------------------------------------------

/**
 * Fires on every post-status transition.
 * Covers:  draft → publish  and  publish → publish (i.e. updates).
 */
add_action( 'transition_post_status', 'indexnow_as_on_status_transition', 10, 3 );
function indexnow_as_on_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
	if ( 'publish' !== $new_status ) {
		return;
	}

	// Skip revisions and autosaves.
	if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
		return;
	}

	$settings = indexnow_as_get_settings();

	if ( ! $settings['auto_submit'] ) {
		return;
	}
	if ( ! in_array( $post->post_type, $settings['post_types'], true ) ) {
		return;
	}

	$url = get_permalink( $post->ID );
	if ( $url ) {
		indexnow_as_submit_urls( array( $url ) );
	}
}

// -------------------------------------------------------------------------
// Admin settings page
// -------------------------------------------------------------------------

add_action( 'admin_menu', function() {
	add_options_page(
		__( 'IndexNow Auto Submit', 'indexnow-auto-submit' ),
		__( 'IndexNow', 'indexnow-auto-submit' ),
		'manage_options',
		'indexnow-auto-submit',
		'indexnow_as_settings_page'
	);
} );

add_action( 'admin_init', function() {
	register_setting(
		'indexnow_as_settings_group',
		INDEXNOW_AS_OPTION_KEY,
		array( 'sanitize_callback' => 'indexnow_as_sanitize_settings' )
	);
} );

function indexnow_as_sanitize_settings( $input ): array {
	$settings          = indexnow_as_get_settings();
	$allowed_endpoints = array(
		'https://api.indexnow.org/indexnow',
		'https://www.bing.com/indexnow',
		'https://yandex.com/indexnow',
	);

	if ( isset( $input['api_key'] ) ) {
		$settings['api_key'] = sanitize_text_field( $input['api_key'] );
	}
	if ( isset( $input['endpoint'] ) && in_array( $input['endpoint'], $allowed_endpoints, true ) ) {
		$settings['endpoint'] = $input['endpoint'];
	}

	$valid_types          = array_keys( get_post_types( array( 'public' => true ) ) );
	$settings['post_types'] = isset( $input['post_types'] ) && is_array( $input['post_types'] )
		? array_intersect( $input['post_types'], $valid_types )
		: array();

	$settings['auto_submit'] = ! empty( $input['auto_submit'] );

	return $settings;
}

function indexnow_as_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// --- Handle: Generate new key ---
	if ( isset( $_POST['indexnow_generate_key'] ) && check_admin_referer( 'indexnow_generate_key' ) ) {
		$settings            = indexnow_as_get_settings();
		$settings['api_key'] = wp_generate_password( 32, false );
		update_option( INDEXNOW_AS_OPTION_KEY, $settings );
		flush_rewrite_rules();
		add_settings_error( 'indexnow_as', 'key_generated', __( 'New API key generated.', 'indexnow-auto-submit' ), 'success' );
	}

	// --- Handle: Bulk submit ---
	if ( isset( $_POST['indexnow_bulk_submit'] ) && check_admin_referer( 'indexnow_bulk_submit' ) ) {
		$settings   = indexnow_as_get_settings();
		$post_ids   = get_posts( array(
			'post_type'      => $settings['post_types'],
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );
		$urls = array_filter( array_map( 'get_permalink', $post_ids ) );

		if ( ! empty( $urls ) ) {
			$result = indexnow_as_submit_urls( array_values( $urls ) );
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'indexnow_as', 'bulk_error', $result->get_error_message(), 'error' );
			} else {
				add_settings_error( 'indexnow_as', 'bulk_success', $result['message'], 'success' );
			}
		} else {
			add_settings_error( 'indexnow_as', 'bulk_empty', __( 'No published posts found for the configured post types.', 'indexnow-auto-submit' ), 'warning' );
		}
	}

	$settings   = indexnow_as_get_settings();
	$public_pts = get_post_types( array( 'public' => true ), 'objects' );
	$log        = get_option( INDEXNOW_AS_LOG_OPTION, array() );
	$key_url    = ! empty( $settings['api_key'] )
		? trailingslashit( get_site_url() ) . $settings['api_key'] . '.txt'
		: '';

	settings_errors( 'indexnow_as' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'IndexNow Auto Submit', 'indexnow-auto-submit' ); ?></h1>
		<p><?php esc_html_e( 'Automatically notify search engines via IndexNow whenever content is published or updated.', 'indexnow-auto-submit' ); ?></p>

		<?php if ( empty( $settings['api_key'] ) ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'No API key is configured. Save the settings below or generate a new key.', 'indexnow-auto-submit' ); ?></p></div>
		<?php endif; ?>

		<!-- ── Settings form ─────────────────────────────────────── -->
		<form method="post" action="options.php">
			<?php settings_fields( 'indexnow_as_settings_group' ); ?>

			<h2 class="title"><?php esc_html_e( 'API Configuration', 'indexnow-auto-submit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="indexnow_api_key"><?php esc_html_e( 'API Key', 'indexnow-auto-submit' ); ?></label>
					</th>
					<td>
						<input type="text"
						       id="indexnow_api_key"
						       name="<?php echo esc_attr( INDEXNOW_AS_OPTION_KEY ); ?>[api_key]"
						       value="<?php echo esc_attr( $settings['api_key'] ); ?>"
						       class="regular-text code" />
						<?php if ( $key_url ) : ?>
							<p class="description">
								<?php echo wp_kses_post( sprintf(
									/* translators: %s: key file URL */
									__( 'Key file served at: <a href="%1$s" target="_blank">%1$s</a>', 'indexnow-auto-submit' ),
									esc_url( $key_url )
								) ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="indexnow_endpoint"><?php esc_html_e( 'Search Engine Endpoint', 'indexnow-auto-submit' ); ?></label>
					</th>
					<td>
						<select id="indexnow_endpoint" name="<?php echo esc_attr( INDEXNOW_AS_OPTION_KEY ); ?>[endpoint]">
							<?php
							$endpoints = array(
								'https://api.indexnow.org/indexnow' => __( 'IndexNow — all participating engines', 'indexnow-auto-submit' ),
								'https://www.bing.com/indexnow'     => __( 'Bing', 'indexnow-auto-submit' ),
								'https://yandex.com/indexnow'       => __( 'Yandex', 'indexnow-auto-submit' ),
							);
							foreach ( $endpoints as $value => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $value ),
									selected( $settings['endpoint'], $value, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
						<p class="description"><?php esc_html_e( 'Submitting to api.indexnow.org distributes to all participating engines automatically.', 'indexnow-auto-submit' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Auto-Submit Settings', 'indexnow-auto-submit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Auto-Submit', 'indexnow-auto-submit' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
							       name="<?php echo esc_attr( INDEXNOW_AS_OPTION_KEY ); ?>[auto_submit]"
							       value="1"
							       <?php checked( $settings['auto_submit'] ); ?> />
							<?php esc_html_e( 'Submit URLs automatically on publish and update', 'indexnow-auto-submit' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post Types', 'indexnow-auto-submit' ); ?></th>
					<td>
						<?php foreach ( $public_pts as $pt ) : ?>
							<label style="display:block; margin-bottom:4px;">
								<input type="checkbox"
								       name="<?php echo esc_attr( INDEXNOW_AS_OPTION_KEY ); ?>[post_types][]"
								       value="<?php echo esc_attr( $pt->name ); ?>"
								       <?php checked( in_array( $pt->name, $settings['post_types'], true ) ); ?> />
								<?php echo esc_html( $pt->labels->singular_name ); ?>
								<code>(<?php echo esc_html( $pt->name ); ?>)</code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<hr />

		<!-- ── Generate new key ──────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Generate New API Key', 'indexnow-auto-submit' ); ?></h2>
		<p><?php esc_html_e( 'Creates a new random 32-character key and invalidates the current one.', 'indexnow-auto-submit' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'indexnow_generate_key' ); ?>
			<input type="hidden" name="indexnow_generate_key" value="1" />
			<?php submit_button( __( 'Generate New Key', 'indexnow-auto-submit' ), 'secondary', 'submit', false ); ?>
		</form>

		<hr />

		<!-- ── Bulk submit ───────────────────────────────────────── -->
		<h2 class="title"><?php esc_html_e( 'Bulk Submit', 'indexnow-auto-submit' ); ?></h2>
		<p><?php esc_html_e( 'Submit up to 100 recently published URLs to IndexNow in one request.', 'indexnow-auto-submit' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'indexnow_bulk_submit' ); ?>
			<input type="hidden" name="indexnow_bulk_submit" value="1" />
			<?php submit_button( __( 'Submit All Published URLs', 'indexnow-auto-submit' ), 'secondary', 'submit', false ); ?>
		</form>

		<!-- ── Submission log ────────────────────────────────────── -->
		<?php if ( ! empty( $log ) ) : ?>
			<hr />
			<h2 class="title"><?php esc_html_e( 'Submission Log', 'indexnow-auto-submit' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Last 50 submissions.', 'indexnow-auto-submit' ); ?></p>
			<table class="widefat striped" style="max-width:960px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Time', 'indexnow-auto-submit' ); ?></th>
						<th><?php esc_html_e( 'Status', 'indexnow-auto-submit' ); ?></th>
						<th><?php esc_html_e( 'URLs', 'indexnow-auto-submit' ); ?></th>
						<th><?php esc_html_e( 'Message', 'indexnow-auto-submit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
							<td>
								<?php if ( in_array( (int) $entry['status_code'], array( 200, 202 ), true ) ) : ?>
									<span style="color:#0a6;font-weight:600;">&#10003; <?php echo esc_html( $entry['status_code'] ); ?></span>
								<?php elseif ( 0 === (int) $entry['status_code'] ) : ?>
									<span style="color:#c00;font-weight:600;">&#10007; ERR</span>
								<?php else : ?>
									<span style="color:#c00;font-weight:600;">&#10007; <?php echo esc_html( $entry['status_code'] ); ?></span>
								<?php endif; ?>
							</td>
							<td style="word-break:break-all;">
								<?php
								$url_list = (array) $entry['urls'];
								echo esc_html( implode( ', ', array_slice( $url_list, 0, 3 ) ) );
								if ( count( $url_list ) > 3 ) {
									echo esc_html( ' … +' . ( count( $url_list ) - 3 ) . ' more' );
								}
								?>
							</td>
							<td><?php echo esc_html( $entry['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<!-- ── REST API reference ────────────────────────────────── -->
		<hr />
		<h2 class="title"><?php esc_html_e( 'REST API', 'indexnow-auto-submit' ); ?></h2>
		<table class="widefat" style="max-width:960px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Method', 'indexnow-auto-submit' ); ?></th>
					<th><?php esc_html_e( 'Endpoint', 'indexnow-auto-submit' ); ?></th>
					<th><?php esc_html_e( 'Description', 'indexnow-auto-submit' ); ?></th>
					<th><?php esc_html_e( 'Capability', 'indexnow-auto-submit' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>POST</code></td>
					<td><code>/wp-json/indexnow/v1/submit</code></td>
					<td><?php esc_html_e( 'Submit an array of URLs. Body: { "urls": ["https://…"] }', 'indexnow-auto-submit' ); ?></td>
					<td><code>edit_posts</code></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/wp-json/indexnow/v1/settings</code></td>
					<td><?php esc_html_e( 'Read current plugin settings (key is masked).', 'indexnow-auto-submit' ); ?></td>
					<td><code>manage_options</code></td>
				</tr>
				<tr>
					<td><code>POST</code></td>
					<td><code>/wp-json/indexnow/v1/settings</code></td>
					<td><?php esc_html_e( 'Update settings. Body: { "api_key", "endpoint", "post_types", "auto_submit" }', 'indexnow-auto-submit' ); ?></td>
					<td><code>manage_options</code></td>
				</tr>
				<tr>
					<td><code>GET</code></td>
					<td><code>/wp-json/indexnow/v1/log</code></td>
					<td><?php esc_html_e( 'Retrieve the last 50 submission log entries.', 'indexnow-auto-submit' ); ?></td>
					<td><code>manage_options</code></td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}

// -------------------------------------------------------------------------
// Admin notice — warn when no key is set
// -------------------------------------------------------------------------

add_action( 'admin_notices', function() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Suppress on the plugin's own settings page.
	$screen = get_current_screen();
	if ( $screen && 'settings_page_indexnow-auto-submit' === $screen->id ) {
		return;
	}
	if ( ! empty( indexnow_as_get_settings()['api_key'] ) ) {
		return;
	}
	printf(
		'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
		wp_kses_post( sprintf(
			/* translators: %s: settings page URL */
			__( '<strong>IndexNow Auto Submit:</strong> No API key configured. <a href="%s">Configure it now</a>.', 'indexnow-auto-submit' ),
			esc_url( admin_url( 'options-general.php?page=indexnow-auto-submit' ) )
		) )
	);
} );
