<?php
/**
 * Optional status of Workflow Captain on Integrations → Connections (suite task "Providerstatus, aktive
 * Workflowlinks und gemeinsame Run-/Correlation-IDs in Suite-Admins anzeigen"): nothing without Workflow Captain
 * (like the connection list), with it adapter state, contract version, counts, last correlation ID and links, every
 * value escaped. Included from tests/integration/run.php after the render files (uses r_render() of r8-render.php).
 *
 * @package WPAffiliateCaptain
 */

// phpcs:disable
use WPAffiliateCaptain\Admin\Pages\IntegrationsPage;
use WPAffiliateCaptain\Integrations\WorkflowCaptainStatus;

echo "R16 Workflow Captain status on the connections tab\n";

$wfc_keep_get = $_GET;
$_GET         = array( 'page' => 'wpac-integrations', 'tab' => 'connections' );
$wfc_page     = r_render( array( IntegrationsPage::class, 'render' ) );
check(
	'WFC without workflow_captain_status_for(): no status, no box on the connections tab (no fixed rows for plugins that are not installed)',
	array( false, null, '', false, array(), '' ),
	array( function_exists( 'workflow_captain_status_for' ), WorkflowCaptainStatus::status(), WorkflowCaptainStatus::html(), false !== strpos( $wfc_page[0], 'Workflow Captain' ), $wfc_page[1], $wfc_page[2] )
);

$wfc_asked = array();
$wfc_base  = 'https://example.test/wp-admin/admin.php';
$wfc_html  = WorkflowCaptainStatus::html(
	static function ( $product ) use ( &$wfc_asked, $wfc_base ) {
		$wfc_asked[] = $product;
		return array(
			'version'             => 1,
			'product'             => $product,
			'adapter'             => 'registered',
			'reason'              => null,
			'contract_version'    => '1.4.0',
			'active_workflows'    => 4,
			'events'              => array( 'done' => 5, 'dead_letter' => 2 ),
			'last_correlation_id' => 'corr"><script>alert(1)</script>',
			'links'               => array(
				'workflows' => $wfc_base . '?page=workflow-captain-workflows',
				'runs'      => $wfc_base . '?page=workflow-captain-runs',
				'events'    => $wfc_base . '?page=workflow-captain-events&product=affiliate-captain',
				'providers' => 'javascript:alert(1)',
			),
		);
	}
);
check(
	'WFC asks for affiliate-captain; adapter, contract version, active workflows and events per state with state filter links',
	array( array( 'affiliate-captain' ), true, true, true, true, true ),
	array(
		$wfc_asked,
		false !== strpos( $wfc_html, '<td>Registered</td>' ),
		false !== strpos( $wfc_html, '<code>1.4.0</code>' ),
		false !== strpos( $wfc_html, '<td>4</td>' ),
		false !== strpos( $wfc_html, 'product=affiliate-captain&amp;state=done">done: 5</a>' ),
		false !== strpos( $wfc_html, 'state=dead_letter">failed: 2</a>' ),
	)
);
check(
	'WFC the correlation ID is escaped and links to the events of Affiliate Captain; a link that is not http(s) is dropped',
	array( false, true, true, true, false, false ),
	array(
		false !== strpos( $wfc_html, '<script>' ),
		false !== strpos( $wfc_html, '<code>corr&quot;&gt;&lt;script&gt;' ),
		false !== strpos( $wfc_html, 'product=affiliate-captain"><code>' ),
		false !== strpos( $wfc_html, 'page=workflow-captain-workflows' ) && false !== strpos( $wfc_html, 'page=workflow-captain-runs' ),
		false !== strpos( $wfc_html, 'javascript:' ),
		false !== strpos( $wfc_html, '>Providers<' ),
	)
);
$wfc_refused = WorkflowCaptainStatus::html(
	static function () {
		return array( 'adapter' => 'refused', 'reason' => 'major <b>2</b>', 'contract_version' => null, 'active_workflows' => 0, 'events' => array(), 'last_correlation_id' => null, 'links' => array() );
	}
);
check(
	'WFC a refused adapter shows its escaped reason, "none yet" and no links; a missing adapter and a null status are reported',
	array( true, true, false, true, true ),
	array(
		false !== strpos( $wfc_refused, 'Refused: major &lt;b&gt;2&lt;/b&gt;' ),
		false !== strpos( $wfc_refused, 'none yet' ),
		false !== strpos( $wfc_refused, 'href=' ),
		false !== strpos( WorkflowCaptainStatus::html( static function () { return array( 'adapter' => 'missing' ); } ), 'Not registered' ),
		false !== strpos( WorkflowCaptainStatus::html( static function () { return null; } ), 'does not report a status yet' ),
	)
);

// From here on the global function exists (declared conditionally, so not before this point).
if ( ! function_exists( 'workflow_captain_status_for' ) ) {
	function workflow_captain_status_for( string $product ): ?array {
		return array( 'product' => $product, 'adapter' => 'registered', 'contract_version' => '1.4.0', 'active_workflows' => 0, 'events' => array(), 'last_correlation_id' => null, 'links' => array() );
	}
}
$wfc_page = r_render( array( IntegrationsPage::class, 'render' ) );
check(
	'WFC with workflow_captain_status_for() the connections tab shows the box, read through the function',
	array( 'affiliate-captain', true, array(), '' ),
	array( WorkflowCaptainStatus::status()['product'] ?? '', false !== strpos( $wfc_page[0], '<h2>Workflow Captain</h2>' ), $wfc_page[1], $wfc_page[2] )
);
$_GET = $wfc_keep_get;
