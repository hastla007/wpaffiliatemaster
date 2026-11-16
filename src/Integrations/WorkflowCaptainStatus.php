<?php
/**
 * Optional status of Workflow Captain for Affiliate Captain on Integrations → Connections (suite task
 * "Providerstatus, aktive Workflowlinks und gemeinsame Run-/Correlation-IDs in Suite-Admins anzeigen").
 *
 * Affiliate Captain neither requires nor configures Workflow Captain: the box reads the public function
 * workflow_captain_status_for() only when it exists and uses no class of Workflow Captain. Like the connection list
 * (no fixed rows for plugins that are not installed) nothing is shown without Workflow Captain. The status holds
 * counts and links only (adapter state, contract version, active workflows, events per state, last correlation ID),
 * no personal data.
 *
 * @package WPAffiliateCaptain
 */

namespace WPAffiliateCaptain\Integrations;

defined( 'ABSPATH' ) || exit;

final class WorkflowCaptainStatus {

	/** Product id of Affiliate Captain in Workflow Captain. */
	const PRODUCT = 'affiliate-captain';

	/** Public status function of Workflow Captain. */
	const SOURCE = 'workflow_captain_status_for';

	/** Link keys of the status, in display order. */
	const LINKS = array( 'workflows', 'runs', 'events', 'providers' );

	/**
	 * Whether Workflow Captain offers its status function.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( self::SOURCE );
	}

	/**
	 * Status of Affiliate Captain in Workflow Captain, or null when Workflow Captain is missing or not loaded yet.
	 *
	 * @param callable|null $source Status function (tests); default workflow_captain_status_for().
	 * @return array|null
	 */
	public static function status( $source = null ) {
		if ( null === $source ) {
			if ( ! self::available() ) {
				return null;
			}
			$source = self::SOURCE;
		}
		$status = call_user_func( $source, self::PRODUCT );
		return is_array( $status ) ? $status : null;
	}

	/**
	 * Label of an event state of Workflow Captain; unknown states are shown by their key.
	 *
	 * @param string $state State key.
	 * @return string
	 */
	private static function state_label( $state ) {
		$labels = array(
			'received'     => __( 'received', 'affiliate-captain' ),
			'processing'   => __( 'processing', 'affiliate-captain' ),
			'done'         => __( 'done', 'affiliate-captain' ),
			'retry_wait'   => __( 'waiting for retry', 'affiliate-captain' ),
			'dead_letter'  => __( 'failed', 'affiliate-captain' ),
			'discarded'    => __( 'discarded', 'affiliate-captain' ),
			'loop_blocked' => __( 'stopped as a loop', 'affiliate-captain' ),
			'paused'       => __( 'paused', 'affiliate-captain' ),
		);
		return isset( $labels[ $state ] ) ? $labels[ $state ] : $state;
	}

	/**
	 * A link of the status; only absolute http(s) links are used.
	 *
	 * @param array  $status Status.
	 * @param string $key    Link key.
	 * @return string
	 */
	private static function link( array $status, $key ) {
		$url = isset( $status['links'][ $key ] ) ? $status['links'][ $key ] : '';
		return is_string( $url ) && 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/**
	 * Wraps escaped HTML in a link when there is a URL.
	 *
	 * @param string $url  URL or ''.
	 * @param string $html Escaped HTML.
	 * @return string
	 */
	private static function anchor( $url, $html ) {
		return '' === $url ? $html : '<a href="' . esc_url( $url ) . '">' . $html . '</a>';
	}

	/**
	 * HTML of the box (escaped); '' without Workflow Captain.
	 *
	 * @param callable|null $source Status function (tests); default workflow_captain_status_for().
	 * @return string
	 */
	public static function html( $source = null ) {
		if ( null === $source && ! self::available() ) {
			return '';
		}
		$html   = '<h2>' . esc_html__( 'Workflow Captain', 'affiliate-captain' ) . '</h2>';
		$status = self::status( $source );
		if ( null === $status ) {
			return $html . '<p>' . esc_html__( 'Workflow Captain is active but does not report a status yet.', 'affiliate-captain' ) . '</p>';
		}
		$adapter = isset( $status['adapter'] ) ? (string) $status['adapter'] : 'missing';
		$reason  = isset( $status['reason'] ) && is_string( $status['reason'] ) ? $status['reason'] : '';
		if ( 'registered' === $adapter ) {
			$adapter_html = esc_html__( 'Registered', 'affiliate-captain' );
		} elseif ( 'refused' === $adapter ) {
			$adapter_html = esc_html__( 'Refused', 'affiliate-captain' ) . ( '' !== $reason ? ': ' . esc_html( $reason ) : '' );
		} else {
			$adapter_html = esc_html__( 'Not registered: Workflow Captain does not know an adapter for Affiliate Captain yet.', 'affiliate-captain' );
		}
		$contract   = isset( $status['contract_version'] ) && is_scalar( $status['contract_version'] ) && '' !== (string) $status['contract_version'] ? '<code>' . esc_html( (string) $status['contract_version'] ) . '</code>' : '—';
		$events_url = self::link( $status, 'events' );
		$events     = array();
		foreach ( isset( $status['events'] ) ? (array) $status['events'] : array() as $state => $count ) {
			$state    = sanitize_key( (string) $state );
			$url      = '' !== $events_url && '' !== $state ? add_query_arg( 'state', $state, $events_url ) : '';
			$events[] = self::anchor( $url, esc_html( self::state_label( $state ) . ': ' . (int) $count ) );
		}
		$last        = isset( $status['last_correlation_id'] ) && is_string( $status['last_correlation_id'] ) ? $status['last_correlation_id'] : '';
		$correlation = '' !== $last ? self::anchor( $events_url, '<code>' . esc_html( $last ) . '</code>' ) : '—';
		$labels      = array(
			'workflows' => __( 'Workflows', 'affiliate-captain' ),
			'runs'      => __( 'Runs', 'affiliate-captain' ),
			'events'    => __( 'Events', 'affiliate-captain' ),
			'providers' => __( 'Providers', 'affiliate-captain' ),
		);
		$links       = array();
		foreach ( self::LINKS as $key ) {
			$url = self::link( $status, $key );
			if ( '' !== $url ) {
				$links[] = self::anchor( $url, esc_html( $labels[ $key ] ) );
			}
		}
		$rows = array(
			__( 'Adapter', 'affiliate-captain' )              => $adapter_html,
			__( 'Contract version', 'affiliate-captain' )     => $contract,
			__( 'Active workflows', 'affiliate-captain' )     => (string) (int) ( isset( $status['active_workflows'] ) ? $status['active_workflows'] : 0 ),
			__( 'Events by state', 'affiliate-captain' )      => $events ? implode( ' · ', $events ) : esc_html__( 'none yet', 'affiliate-captain' ),
			__( 'Last correlation ID', 'affiliate-captain' )  => $correlation,
		);
		$html .= '<table class="widefat striped wpac-workflow-captain"><tbody>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( $links ) {
			$html .= '<p>' . esc_html__( 'Open in Workflow Captain:', 'affiliate-captain' ) . ' ' . implode( ' · ', $links ) . '</p>';
		}
		return $html;
	}

	/**
	 * Prints the box (nothing without Workflow Captain).
	 */
	public static function render() {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
	}
}
