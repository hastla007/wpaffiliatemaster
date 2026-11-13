<?php
/**
 * WordPress content integration:
 * - Server-rendered editor blocks for the application form, partner area,
 *   partner welcome box and a list of recommended partner targets.
 * - "Affiliate Captain" panel on posts/pages/CPTs: partner statistics of the
 *   content, deep-link format and the option to recommend the content as a
 *   partner target (with an editor note shown in the partner area).
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Content;

use WPAffiliateCaptain\Affiliates\Registration;
use WPAffiliateCaptain\Codes\CodeRepository;
use WPAffiliateCaptain\Core\Capabilities;
use WPAffiliateCaptain\Core\Money;
use WPAffiliateCaptain\Core\Settings;
use WPAffiliateCaptain\Portal\Portal;
use WPAffiliateCaptain\Promotion\Landing;
use WPAffiliateCaptain\Reports\Reports;

defined( 'ABSPATH' ) || exit;

final class Editor {

	const META_PROMOTE = '_wpac_promote';
	const META_NOTE    = '_wpac_promote_note';

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_shortcode( 'wpac_partner_targets', array( __CLASS__, 'render_targets' ) );
	}

	/**
	 * Register blocks (no build step; editor script uses wp.* globals).
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'wpac-blocks',
			WPAC_URL . 'assets/js/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			WPAC_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wpac-blocks', 'affiliate-captain', WPAC_DIR . 'languages' );
		}
		$dir = WPAC_DIR . 'blocks/';
		register_block_type(
			$dir . 'registration',
			array(
				'render_callback' => static function ( $atts ) {
					return Registration::render( array( 'group' => isset( $atts['group'] ) ? (int) $atts['group'] : 0 ) );
				},
			)
		);
		register_block_type(
			$dir . 'portal',
			array(
				'render_callback' => static function () {
					return Portal::render();
				},
			)
		);
		register_block_type(
			$dir . 'partner-welcome',
			array(
				'render_callback' => static function ( $atts ) {
					$text = isset( $atts['text'] ) && '' !== $atts['text'] ? array( 'text' => $atts['text'] ) : array();
					// Placeholder only for the editor preview (block renderer, context=edit) – not for headless clients.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['context'] ) && 'edit' === $_GET['context'] ) {
						return '<div class="wpac-welcome"><strong>' . esc_html__( 'Recommended by [partner name]', 'affiliate-captain' ) . '</strong><p>' . esc_html__( 'Visible only for visitors coming via a partner link.', 'affiliate-captain' ) . '</p></div>';
					}
					return Landing::welcome( $text );
				},
			)
		);
		register_block_type(
			$dir . 'partner-targets',
			array(
				'render_callback' => static function () {
					return self::render_targets();
				},
			)
		);
	}

	/**
	 * Data for the block editor (partner groups for the application form block).
	 */
	public static function editor_data() {
		$groups = array(
			array(
				'value' => 0,
				'label' => __( '— Applicant chooses —', 'affiliate-captain' ),
			),
		);
		foreach ( \WPAffiliateCaptain\Affiliates\Groups::options() as $id => $name ) {
			$groups[] = array(
				'value' => (int) $id,
				'label' => $name,
			);
		}
		wp_add_inline_script( 'wpac-blocks', 'window.wpacBlocks = ' . wp_json_encode( array( 'groups' => $groups ) ) . ';', 'before' );
	}

	/**
	 * Public post types for the panel.
	 *
	 * @return string[]
	 */
	private static function post_types() {
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	}

	public static function add_meta_box() {
		if ( ! current_user_can( Capabilities::MANAGE_AFFILIATES ) && ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		add_meta_box( 'wpac-content', __( 'Affiliate Captain', 'affiliate-captain' ), array( __CLASS__, 'render_meta_box' ), self::post_types(), 'side', 'low' );
	}

	/**
	 * Panel output.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		$currency = (string) Settings::get( 'currency' );
		if ( 'publish' === $post->post_status ) {
			$link = add_query_arg( (string) Settings::get( 'referral_param' ), 'CODE', get_permalink( $post ) );
			echo '<p><strong>' . esc_html__( 'Partner deep link', 'affiliate-captain' ) . '</strong><br><code style="word-break:break-all">' . esc_html( $link ) . '</code></p>';
		}
		if ( current_user_can( Capabilities::MANAGE_AFFILIATES ) ) {
			$stats = Reports::for_content( (int) $post->ID, 90 );
			echo '<p><strong>' . esc_html__( 'Partner traffic (90 days)', 'affiliate-captain' ) . '</strong><br>';
			$visits   = (int) $stats['visits'];
			$partners = (int) $stats['partners'];
			$orders   = (int) $stats['orders'];
			echo esc_html(
				sprintf(
					/* translators: 1: "3 visits", 2: "2 partners", 3: "1 order" */
					__( '%1$s from %2$s, %3$s', 'affiliate-captain' ),
					/* translators: %d: number of visits */
					sprintf( _n( '%d visit', '%d visits', $visits, 'affiliate-captain' ), $visits ),
					/* translators: %d: number of partners */
					sprintf( _n( '%d partner', '%d partners', $partners, 'affiliate-captain' ), $partners ),
					/* translators: %d: number of orders */
					sprintf( _n( '%d order', '%d orders', $orders, 'affiliate-captain' ), $orders )
				)
			) . '<br>';
			/* translators: 1: revenue, 2: commission */
			echo esc_html( sprintf( __( 'Net revenue %1$s, commission %2$s', 'affiliate-captain' ), Money::format( $stats['revenue'], $currency ), Money::format( $stats['commission'], $currency ) ) ) . '</p>';
		}
		wp_nonce_field( 'wpac_content_' . $post->ID, '_wpac_content_nonce' );
		echo '<p><label><input type="checkbox" name="wpac_promote" value="1" ' . checked( (bool) get_post_meta( $post->ID, self::META_PROMOTE, true ), true, false ) . '> ' . esc_html__( 'Recommend as partner target', 'affiliate-captain' ) . '</label></p>';
		echo '<p><label for="wpac_promote_note">' . esc_html__( 'Note for partners', 'affiliate-captain' ) . '</label><textarea id="wpac_promote_note" name="wpac_promote_note" rows="3" style="width:100%">' . esc_textarea( (string) get_post_meta( $post->ID, self::META_NOTE, true ) ) . '</textarea></p>';
		echo '<p class="description">' . esc_html__( 'Recommended targets appear in the partner area with a ready-made partner link.', 'affiliate-captain' ) . '</p>';
	}

	/**
	 * Save panel.
	 *
	 * @param int      $post_id Post.
	 * @param \WP_Post $post    Post.
	 */
	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['_wpac_content_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpac_content_nonce'] ) ), 'wpac_content_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $_POST['wpac_promote'] ) ) {
			update_post_meta( $post_id, self::META_PROMOTE, 1 );
		} else {
			delete_post_meta( $post_id, self::META_PROMOTE );
		}
		$note = isset( $_POST['wpac_promote_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpac_promote_note'] ) ) : '';
		if ( '' !== $note ) {
			update_post_meta( $post_id, self::META_NOTE, $note );
		} else {
			delete_post_meta( $post_id, self::META_NOTE );
		}
	}

	/**
	 * Recommended targets.
	 *
	 * @return \WP_Post[]
	 */
	public static function targets() {
		return get_posts(
			array(
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'numberposts'      => 50,
				'meta_key'         => self::META_PROMOTE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
	}

	/**
	 * Recommended targets with personal links (for logged-in partners).
	 *
	 * @return string
	 */
	public static function render_targets() {
		$targets = self::targets();
		if ( ! $targets ) {
			return '';
		}
		$aff  = is_user_logged_in() ? \WPAffiliateCaptain\Affiliates\AffiliateRepository::find_by_user( get_current_user_id() ) : null;
		$code = $aff && 'active' === $aff->status ? CodeRepository::main_code( (int) $aff->id ) : '';
		$out  = '<div class="wpac-targets"><h3>' . esc_html__( 'Recommended target pages', 'affiliate-captain' ) . '</h3><ul>';
		foreach ( $targets as $p ) {
			$url  = get_permalink( $p );
			$link = $code ? add_query_arg( (string) Settings::get( 'referral_param' ), $code, $url ) : $url;
			$note = (string) get_post_meta( $p->ID, self::META_NOTE, true );
			$out .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( get_the_title( $p ) ) . '</a>';
			if ( $note ) {
				$out .= '<br><span class="wpac-muted">' . esc_html( $note ) . '</span>';
			}
			if ( $code ) {
				$out .= '<input type="text" readonly class="wpac-copy" value="' . esc_attr( $link ) . '" onclick="this.select()">';
			}
			$out .= '</li>';
		}
		return $out . '</ul></div>';
	}
}
