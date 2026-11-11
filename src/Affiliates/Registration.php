<?php
/**
 * Public application form: [wpac_affiliate_registration].
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Affiliates;

use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Tracking\RateLimiter;

defined( 'ABSPATH' ) || exit;

final class Registration {

	const SHORTCODE = 'wpac_affiliate_registration';

	public static function register_hooks() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'init', array( __CLASS__, 'handle_submit' ) );
	}

	/**
	 * Process the form (POST-redirect-GET).
	 */
	public static function handle_submit() {
		if ( empty( $_POST['wpac_register'] ) ) {
			return;
		}
		$redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$redirect = remove_query_arg( array( 'wpac_reg', 'wpac_err' ), $redirect );

		if ( ! isset( $_POST['_wpac_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_nonce'] ) ), 'wpac_register' ) ) {
			self::back( $redirect, 'nonce' );
		}
		// Honeypot.
		if ( ! empty( $_POST['wpac_url_confirm'] ) ) {
			self::back( $redirect, 'spam' );
		}
		if ( ! RateLimiter::allow( 'register', 5, HOUR_IN_SECONDS ) ) {
			self::back( $redirect, 'rate' );
		}

		$terms = Agreements::current();
		if ( $terms['page_id'] && empty( $_POST['wpac_terms'] ) ) {
			self::back( $redirect, 'terms' );
		}

		$user  = wp_get_current_user();
		$email = $user && $user->exists() ? $user->user_email : ( isset( $_POST['wpac_email'] ) ? sanitize_email( wp_unslash( $_POST['wpac_email'] ) ) : '' );
		if ( $user && $user->exists() && AffiliateRepository::find_by_user( $user->ID ) ) {
			self::back( $redirect, 'exists' );
		}

		$result = AffiliateRepository::create(
			array(
				'user_id'          => $user && $user->exists() ? $user->ID : 0,
				'name'             => isset( $_POST['wpac_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_name'] ) ) : '',
				'email'            => $email,
				'website'          => isset( $_POST['wpac_website'] ) ? esc_url_raw( wp_unslash( $_POST['wpac_website'] ) ) : '',
				'channel'          => isset( $_POST['wpac_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['wpac_channel'] ) ) : '',
				'application_note' => isset( $_POST['wpac_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpac_note'] ) ) : '',
				'status'           => Settings::get( 'auto_approve' ) ? 'active' : 'pending',
			)
		);
		if ( is_wp_error( $result ) ) {
			self::back( $redirect, 'exists' === substr( $result->get_error_code(), 5 ) ? 'exists' : 'invalid' );
		}
		if ( $terms['page_id'] ) {
			Agreements::record( $result, 'accepted' );
		}
		if ( ! Settings::get( 'auto_approve' ) ) {
			Notifications::application_received( $result );
		}
		wp_safe_redirect( add_query_arg( 'wpac_reg', 'ok', $redirect ) );
		exit;
	}

	/**
	 * Redirect back with an error.
	 *
	 * @param string $url  URL.
	 * @param string $code Error code.
	 */
	private static function back( $url, $code ) {
		wp_safe_redirect( add_query_arg( 'wpac_err', $code, $url ) );
		exit;
	}

	/**
	 * Shortcode output.
	 *
	 * @return string
	 */
	public static function render() {
		wp_enqueue_style( 'wpac-portal', WPAC_URL . 'assets/css/portal.css', array(), WPAC_VERSION );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['wpac_reg'] ) ) {
			$msg = Settings::get( 'auto_approve' )
				? __( 'Thank you! Your partner account is active. Check your e-mail for login details.', 'wp-affiliate-captain' )
				: __( 'Thank you for your application! We will review it and get back to you by e-mail.', 'wp-affiliate-captain' );
			return '<div class="wpac-notice wpac-notice--success">' . esc_html( $msg ) . '</div>';
		}

		$user = wp_get_current_user();
		if ( $user->exists() ) {
			$aff = AffiliateRepository::find_by_user( $user->ID );
			if ( $aff ) {
				$portal = (int) Settings::get( 'portal_page_id' );
				$link   = $portal ? ' <a href="' . esc_url( get_permalink( $portal ) ) . '">' . esc_html__( 'Go to partner area', 'wp-affiliate-captain' ) . '</a>' : '';
				return '<div class="wpac-notice">' . esc_html__( 'You already have a partner account.', 'wp-affiliate-captain' ) . $link . '</div>';
			}
		}

		$errors = array(
			'nonce'   => __( 'The form expired. Please try again.', 'wp-affiliate-captain' ),
			'spam'    => __( 'Your submission could not be processed.', 'wp-affiliate-captain' ),
			'rate'    => __( 'Too many attempts. Please try again later.', 'wp-affiliate-captain' ),
			'terms'   => __( 'Please accept the partner program terms.', 'wp-affiliate-captain' ),
			'exists'  => __( 'An affiliate account with this e-mail address already exists.', 'wp-affiliate-captain' ),
			'invalid' => __( 'Please check your entries.', 'wp-affiliate-captain' ),
		);
		$err    = isset( $_GET['wpac_err'] ) ? sanitize_key( wp_unslash( $_GET['wpac_err'] ) ) : '';
		// phpcs:enable

		$terms = Agreements::current();

		ob_start();
		?>
		<form class="wpac-form" method="post">
			<?php if ( $err && isset( $errors[ $err ] ) ) : ?>
				<div class="wpac-notice wpac-notice--error" role="alert"><?php echo esc_html( $errors[ $err ] ); ?></div>
			<?php endif; ?>
			<?php wp_nonce_field( 'wpac_register', '_wpac_nonce' ); ?>
			<input type="hidden" name="wpac_register" value="1">
			<p class="wpac-hp" aria-hidden="true"><label>URL <input type="text" name="wpac_url_confirm" tabindex="-1" autocomplete="off"></label></p>
			<p>
				<label for="wpac_name"><?php esc_html_e( 'Name / company', 'wp-affiliate-captain' ); ?> *</label>
				<input type="text" id="wpac_name" name="wpac_name" required value="<?php echo esc_attr( $user->exists() ? $user->display_name : '' ); ?>">
			</p>
			<?php if ( ! $user->exists() ) : ?>
			<p>
				<label for="wpac_email"><?php esc_html_e( 'E-mail', 'wp-affiliate-captain' ); ?> *</label>
				<input type="email" id="wpac_email" name="wpac_email" required autocomplete="email">
			</p>
			<?php endif; ?>
			<p>
				<label for="wpac_website"><?php esc_html_e( 'Website', 'wp-affiliate-captain' ); ?></label>
				<input type="url" id="wpac_website" name="wpac_website" placeholder="https://">
			</p>
			<p>
				<label for="wpac_channel"><?php esc_html_e( 'Channel (e.g. Instagram, YouTube, newsletter, school)', 'wp-affiliate-captain' ); ?></label>
				<input type="text" id="wpac_channel" name="wpac_channel">
			</p>
			<p>
				<label for="wpac_note"><?php esc_html_e( 'How do you want to promote us?', 'wp-affiliate-captain' ); ?></label>
				<textarea id="wpac_note" name="wpac_note" rows="4"></textarea>
			</p>
			<?php if ( $terms['page_id'] ) : ?>
			<p class="wpac-check">
				<label>
					<input type="checkbox" name="wpac_terms" value="1" required>
					<?php
					printf(
						/* translators: 1: link to terms, 2: version */
						esc_html__( 'I accept the %1$s (version %2$s).', 'wp-affiliate-captain' ),
						'<a href="' . esc_url( $terms['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'partner program terms', 'wp-affiliate-captain' ) . '</a>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						esc_html( $terms['version'] )
					);
					?>
				</label>
			</p>
			<?php endif; ?>
			<p><button type="submit" class="wpac-button"><?php esc_html_e( 'Apply now', 'wp-affiliate-captain' ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
