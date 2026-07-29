<?php
/**
 * Plugin Name:       GOTV Lead Tracker
 * Plugin URI:        https://gotvadvantage.com
 * Description:        Capture registration leads and tag each one with the campaign/source it came from. Includes a registration form shortcode, source tracking via link parameters, confirmation emails, and an admin screen to view, filter, and export leads by source.
 * Version:           1.0.0
 * Author:            Anirudha Talmale
 * License:           GPL-2.0+
 * Text Domain:       gotv-lead-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'GOTVLT_VERSION', '1.0.0' );
define( 'GOTVLT_TABLE', 'gotv_leads' );
define( 'GOTVLT_FILE', __FILE__ );

/**
 * ---------------------------------------------------------------------------
 * Activation: create the leads table.
 * ---------------------------------------------------------------------------
 */
function gotvlt_activate() {
	global $wpdb;
	$table           = $wpdb->prefix . GOTVLT_TABLE;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL DEFAULT '',
		email VARCHAR(191) NOT NULL DEFAULT '',
		phone VARCHAR(64) NOT NULL DEFAULT '',
		source VARCHAR(191) NOT NULL DEFAULT 'direct',
		utm_source VARCHAR(191) NOT NULL DEFAULT '',
		utm_medium VARCHAR(191) NOT NULL DEFAULT '',
		utm_campaign VARCHAR(191) NOT NULL DEFAULT '',
		landing_url TEXT NULL,
		referrer TEXT NULL,
		ip_address VARCHAR(64) NOT NULL DEFAULT '',
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY source (source),
		KEY created_at (created_at)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	add_option( 'gotvlt_confirmation_subject', 'Thanks for registering with GOTV Advantage' );
	add_option(
		'gotvlt_confirmation_body',
		"Hi {name},\n\nThanks for registering! We've received your details and someone from our team will be in touch shortly.\n\nTalk soon,\nThe GOTV Advantage Team"
	);
}
register_activation_hook( GOTVLT_FILE, 'gotvlt_activate' );

/**
 * Helper: fully-qualified table name.
 */
function gotvlt_table() {
	global $wpdb;
	return $wpdb->prefix . GOTVLT_TABLE;
}

/**
 * ---------------------------------------------------------------------------
 * Front-end: registration form shortcode  [gotv_register]
 * ---------------------------------------------------------------------------
 *
 * Attributes:
 *   title       Heading shown above the form.
 *   button      Submit button label.
 *   thankyou    On-screen thank-you message after submit.
 */
function gotvlt_register_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'title'    => 'Register',
			'button'   => 'Submit',
			'thankyou' => "Thank you! You're registered — check your inbox for a confirmation email.",
		),
		$atts,
		'gotv_register'
	);

	$notice   = '';
	$success  = false;

	// Handle submission.
	if ( isset( $_POST['gotvlt_submit'] ) ) {
		$result = gotvlt_handle_submission();
		if ( true === $result ) {
			$success = true;
		} else {
			$notice = $result; // Error string.
		}
	}

	// The source/UTM values come in on the URL and we stash them in hidden
	// fields so they survive the POST even after the redirect-free submit.
	$source       = isset( $_REQUEST['src'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['src'] ) ) : ( isset( $_REQUEST['source'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['source'] ) ) : 'direct' );
	$utm_source   = isset( $_REQUEST['utm_source'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['utm_source'] ) ) : '';
	$utm_medium   = isset( $_REQUEST['utm_medium'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['utm_medium'] ) ) : '';
	$utm_campaign = isset( $_REQUEST['utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['utm_campaign'] ) ) : '';

	ob_start();
	?>
	<div class="gotvlt-form-wrap">
		<?php if ( $success ) : ?>
			<div class="gotvlt-thankyou"><?php echo esc_html( $atts['thankyou'] ); ?></div>
		<?php else : ?>
			<?php if ( $notice ) : ?>
				<div class="gotvlt-error"><?php echo esc_html( $notice ); ?></div>
			<?php endif; ?>
			<form class="gotvlt-form" method="post">
				<?php if ( $atts['title'] ) : ?>
					<h3 class="gotvlt-form-title"><?php echo esc_html( $atts['title'] ); ?></h3>
				<?php endif; ?>

				<label class="gotvlt-field">
					<span>Name</span>
					<input type="text" name="gotvlt_name" required value="<?php echo isset( $_POST['gotvlt_name'] ) ? esc_attr( wp_unslash( $_POST['gotvlt_name'] ) ) : ''; ?>">
				</label>

				<label class="gotvlt-field">
					<span>Email</span>
					<input type="email" name="gotvlt_email" required value="<?php echo isset( $_POST['gotvlt_email'] ) ? esc_attr( wp_unslash( $_POST['gotvlt_email'] ) ) : ''; ?>">
				</label>

				<label class="gotvlt-field">
					<span>Phone</span>
					<input type="tel" name="gotvlt_phone" required value="<?php echo isset( $_POST['gotvlt_phone'] ) ? esc_attr( wp_unslash( $_POST['gotvlt_phone'] ) ) : ''; ?>">
				</label>

				<?php wp_nonce_field( 'gotvlt_submit_action', 'gotvlt_nonce' ); ?>
				<input type="hidden" name="gotvlt_source" value="<?php echo esc_attr( $source ); ?>">
				<input type="hidden" name="gotvlt_utm_source" value="<?php echo esc_attr( $utm_source ); ?>">
				<input type="hidden" name="gotvlt_utm_medium" value="<?php echo esc_attr( $utm_medium ); ?>">
				<input type="hidden" name="gotvlt_utm_campaign" value="<?php echo esc_attr( $utm_campaign ); ?>">
				<input type="hidden" name="gotvlt_landing" value="<?php echo esc_attr( gotvlt_current_url() ); ?>">

				<button type="submit" name="gotvlt_submit" value="1" class="gotvlt-btn"><?php echo esc_html( $atts['button'] ); ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}
add_shortcode( 'gotv_register', 'gotvlt_register_shortcode' );

/**
 * Process a submitted registration. Returns true on success or an error string.
 */
function gotvlt_handle_submission() {
	if ( ! isset( $_POST['gotvlt_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gotvlt_nonce'] ) ), 'gotvlt_submit_action' ) ) {
		return 'Security check failed. Please refresh the page and try again.';
	}

	$name  = isset( $_POST['gotvlt_name'] ) ? sanitize_text_field( wp_unslash( $_POST['gotvlt_name'] ) ) : '';
	$email = isset( $_POST['gotvlt_email'] ) ? sanitize_email( wp_unslash( $_POST['gotvlt_email'] ) ) : '';
	$phone = isset( $_POST['gotvlt_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['gotvlt_phone'] ) ) : '';

	if ( '' === $name || '' === $email || '' === $phone ) {
		return 'Please fill in your name, email and phone.';
	}
	if ( ! is_email( $email ) ) {
		return 'That email address doesn\'t look right — please double-check it.';
	}

	$source       = isset( $_POST['gotvlt_source'] ) ? sanitize_text_field( wp_unslash( $_POST['gotvlt_source'] ) ) : 'direct';
	$source       = '' === $source ? 'direct' : $source;
	$utm_source   = isset( $_POST['gotvlt_utm_source'] ) ? sanitize_text_field( wp_unslash( $_POST['gotvlt_utm_source'] ) ) : '';
	$utm_medium   = isset( $_POST['gotvlt_utm_medium'] ) ? sanitize_text_field( wp_unslash( $_POST['gotvlt_utm_medium'] ) ) : '';
	$utm_campaign = isset( $_POST['gotvlt_utm_campaign'] ) ? sanitize_text_field( wp_unslash( $_POST['gotvlt_utm_campaign'] ) ) : '';
	$landing      = isset( $_POST['gotvlt_landing'] ) ? esc_url_raw( wp_unslash( $_POST['gotvlt_landing'] ) ) : '';

	global $wpdb;
	$inserted = $wpdb->insert(
		gotvlt_table(),
		array(
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'source'       => $source,
			'utm_source'   => $utm_source,
			'utm_medium'   => $utm_medium,
			'utm_campaign' => $utm_campaign,
			'landing_url'  => $landing,
			'referrer'     => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'ip_address'   => gotvlt_client_ip(),
			'created_at'   => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( false === $inserted ) {
		return 'Something went wrong saving your registration. Please try again.';
	}

	gotvlt_send_confirmation( $name, $email );

	// Fire an action so other integrations (CRM, webhooks) can hook in later.
	do_action( 'gotvlt_lead_registered', $wpdb->insert_id, compact( 'name', 'email', 'phone', 'source' ) );

	return true;
}

/**
 * Send the confirmation email to the registrant.
 */
function gotvlt_send_confirmation( $name, $email ) {
	$subject = get_option( 'gotvlt_confirmation_subject', 'Thanks for registering' );
	$body    = get_option( 'gotvlt_confirmation_body', "Hi {name},\n\nThanks for registering!" );
	$body    = str_replace( '{name}', $name, $body );

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

	return wp_mail( $email, $subject, $body, $headers );
}

/**
 * Best-effort client IP.
 */
function gotvlt_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return $ip;
}

/**
 * Current full URL (used to record the landing page a lead came in on).
 */
function gotvlt_current_url() {
	$scheme = is_ssl() ? 'https' : 'http';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	return esc_url_raw( $scheme . '://' . $host . $uri );
}

/**
 * Front-end styles (kept tiny and dependency-free).
 */
function gotvlt_front_styles() {
	?>
	<style>
		.gotvlt-form-wrap{max-width:440px;margin:0 auto;font-family:inherit}
		.gotvlt-form{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,.05)}
		.gotvlt-form-title{margin:0 0 18px;font-size:22px;color:#111}
		.gotvlt-field{display:block;margin-bottom:16px}
		.gotvlt-field span{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:6px}
		.gotvlt-field input{width:100%;padding:11px 13px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;box-sizing:border-box}
		.gotvlt-field input:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
		.gotvlt-btn{width:100%;padding:12px;background:#2563eb;color:#fff;border:0;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer}
		.gotvlt-btn:hover{background:#1d4ed8}
		.gotvlt-thankyou{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;padding:18px;border-radius:10px;text-align:center;font-size:16px}
		.gotvlt-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:14px}
	</style>
	<?php
}
add_action( 'wp_head', 'gotvlt_front_styles' );

/**
 * ---------------------------------------------------------------------------
 * Admin: menu, list screen, CSV export, settings.
 * ---------------------------------------------------------------------------
 */
function gotvlt_admin_menu() {
	add_menu_page(
		'Registrations',
		'Registrations',
		'manage_options',
		'gotvlt-leads',
		'gotvlt_leads_page',
		'dashicons-groups',
		26
	);
	add_submenu_page(
		'gotvlt-leads',
		'Registrations',
		'All Leads',
		'manage_options',
		'gotvlt-leads',
		'gotvlt_leads_page'
	);
	add_submenu_page(
		'gotvlt-leads',
		'Email Settings',
		'Email Settings',
		'manage_options',
		'gotvlt-settings',
		'gotvlt_settings_page'
	);
}
add_action( 'admin_menu', 'gotvlt_admin_menu' );

/**
 * Handle CSV export early, before any HTML is sent.
 */
function gotvlt_maybe_export_csv() {
	if ( ! is_admin() ) {
		return;
	}
	if ( ! isset( $_GET['page'], $_GET['gotvlt_export'] ) || 'gotvlt-leads' !== $_GET['page'] ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'gotvlt_export_action' );

	global $wpdb;
	$table  = gotvlt_table();
	$source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';

	if ( '' !== $source ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s ORDER BY created_at DESC", $source ), ARRAY_A );
	} else {
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'ID', 'Name', 'Email', 'Phone', 'Source', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'Registered' ) );
	foreach ( (array) $rows as $r ) {
		fputcsv( $out, array( $r['id'], $r['name'], $r['email'], $r['phone'], $r['source'], $r['utm_source'], $r['utm_medium'], $r['utm_campaign'], $r['created_at'] ) );
	}
	fclose( $out );
	exit;
}
add_action( 'admin_init', 'gotvlt_maybe_export_csv' );

/**
 * The main Leads admin screen.
 */
function gotvlt_leads_page() {
	global $wpdb;
	$table = gotvlt_table();

	$filter_source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';

	// Distinct sources for the filter dropdown + counts.
	$sources = $wpdb->get_results( "SELECT source, COUNT(*) AS cnt FROM {$table} GROUP BY source ORDER BY cnt DESC", ARRAY_A );
	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

	if ( '' !== $filter_source ) {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source = %s ORDER BY created_at DESC", $filter_source ), ARRAY_A );
	} else {
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A );
	}

	$export_url = wp_nonce_url( add_query_arg( array( 'page' => 'gotvlt-leads', 'source' => $filter_source, 'gotvlt_export' => 1 ), admin_url( 'admin.php' ) ), 'gotvlt_export_action' );
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Registrations</h1>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
		<hr class="wp-header-end">

		<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">
			<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 20px">
				<div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.4px">Total Leads</div>
				<div style="font-size:26px;font-weight:700;color:#1d2327"><?php echo esc_html( $total ); ?></div>
			</div>
			<?php foreach ( array_slice( (array) $sources, 0, 5 ) as $s ) : ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 20px">
					<div style="font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.4px"><?php echo esc_html( $s['source'] ); ?></div>
					<div style="font-size:26px;font-weight:700;color:#2271b1"><?php echo esc_html( $s['cnt'] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>

		<form method="get" style="margin:12px 0">
			<input type="hidden" name="page" value="gotvlt-leads">
			<label style="font-weight:600;margin-right:8px">Filter by source:</label>
			<select name="source" onchange="this.form.submit()">
				<option value="">All sources (<?php echo esc_html( $total ); ?>)</option>
				<?php foreach ( (array) $sources as $s ) : ?>
					<option value="<?php echo esc_attr( $s['source'] ); ?>" <?php selected( $filter_source, $s['source'] ); ?>>
						<?php echo esc_html( $s['source'] . ' (' . $s['cnt'] . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $filter_source ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=gotvlt-leads' ) ); ?>" class="button" style="margin-left:8px">Clear</a>
			<?php endif; ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:40px">ID</th>
					<th>Name</th>
					<th>Email</th>
					<th>Phone</th>
					<th>Source</th>
					<th>Campaign (UTM)</th>
					<th>Registered</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7">No registrations yet.</td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $r ) : ?>
						<tr>
							<td><?php echo esc_html( $r['id'] ); ?></td>
							<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
							<td><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a></td>
							<td><?php echo esc_html( $r['phone'] ); ?></td>
							<td><span style="display:inline-block;background:#f0f6fc;color:#0a4b78;border:1px solid #c5d9ed;border-radius:4px;padding:2px 8px;font-size:12px"><?php echo esc_html( $r['source'] ); ?></span></td>
							<td><?php echo esc_html( trim( $r['utm_campaign'] . ' ' . $r['utm_medium'] ) ?: '—' ); ?></td>
							<td><?php echo esc_html( $r['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * Email settings screen.
 */
function gotvlt_settings_page() {
	if ( isset( $_POST['gotvlt_save_settings'] ) && check_admin_referer( 'gotvlt_settings_action' ) ) {
		update_option( 'gotvlt_confirmation_subject', sanitize_text_field( wp_unslash( $_POST['gotvlt_confirmation_subject'] ?? '' ) ) );
		update_option( 'gotvlt_confirmation_body', sanitize_textarea_field( wp_unslash( $_POST['gotvlt_confirmation_body'] ?? '' ) ) );
		echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
	}

	$subject = get_option( 'gotvlt_confirmation_subject', '' );
	$body    = get_option( 'gotvlt_confirmation_body', '' );
	?>
	<div class="wrap">
		<h1>Confirmation Email</h1>
		<p>This email is sent automatically to every visitor the moment they register. Use <code>{name}</code> to insert the registrant's name.</p>
		<form method="post">
			<?php wp_nonce_field( 'gotvlt_settings_action' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="gotvlt_confirmation_subject">Subject</label></th>
					<td><input name="gotvlt_confirmation_subject" id="gotvlt_confirmation_subject" type="text" class="regular-text" value="<?php echo esc_attr( $subject ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="gotvlt_confirmation_body">Message</label></th>
					<td><textarea name="gotvlt_confirmation_body" id="gotvlt_confirmation_body" rows="10" class="large-text"><?php echo esc_textarea( $body ); ?></textarea></td>
				</tr>
			</table>
			<p class="submit"><button type="submit" name="gotvlt_save_settings" class="button button-primary">Save Changes</button></p>
		</form>

		<hr>
		<h2>How to build tracking links</h2>
		<p>Point every campaign at your registration page and add <code>?src=</code> with a label of your choice. Each label shows up as its own source in the table above.</p>
		<ul style="list-style:disc;margin-left:22px">
			<li><code><?php echo esc_html( home_url( '/register/?src=facebook' ) ); ?></code></li>
			<li><code><?php echo esc_html( home_url( '/register/?src=flyer-jan' ) ); ?></code></li>
			<li><code><?php echo esc_html( home_url( '/register/?src=radio-ad' ) ); ?></code></li>
		</ul>
		<p>Running paid ads? Standard <code>utm_source</code>, <code>utm_medium</code> and <code>utm_campaign</code> parameters are captured automatically too.</p>
	</div>
	<?php
}
