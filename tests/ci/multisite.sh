#!/usr/bin/env bash
# Multisite check: converts a fresh test site (install-wp.sh) into a network, activates the plugin
# network-wide and checks tables, schema and clean-up per site.
#   WP_URL=http://example.test tests/ci/install-wp.sh <wp-dir> && tests/ci/multisite.sh <wp-dir>
set -euo pipefail
WP_DIR="${1:?wp dir}"
DIR="$(cd "$(dirname "$0")" && pwd)"
php "$DIR/multisite-network.php" "$WP_DIR"
# Network constants go before wp-settings.php is loaded.
php -r '
$f = $argv[1] . "/wp-config.php";
$c = file_get_contents( $f );
if ( false === strpos( $c, "MULTISITE" ) ) {
	$add = "define( \"WP_ALLOW_MULTISITE\", true );\ndefine( \"MULTISITE\", true );\ndefine( \"SUBDOMAIN_INSTALL\", false );\ndefine( \"DOMAIN_CURRENT_SITE\", \"example.test\" );\ndefine( \"PATH_CURRENT_SITE\", \"/\" );\ndefine( \"SITE_ID_CURRENT_SITE\", 1 );\ndefine( \"BLOG_ID_CURRENT_SITE\", 1 );\n";
	$c   = str_replace( "if ( ! defined( \x27ABSPATH\x27 ) )", $add . "if ( ! defined( \x27ABSPATH\x27 ) )", $c );
	$c   = preg_replace( "/define\( .WP_HOME.*\n/", "", $c );
	$c   = preg_replace( "/define\( .WP_SITEURL.*\n/", "", $c );
	file_put_contents( $f, $c );
}' "$WP_DIR"
php "$DIR/multisite-check.php" "$WP_DIR"
