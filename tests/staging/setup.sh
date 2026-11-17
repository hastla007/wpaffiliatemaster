#!/bin/sh
# Entry point of the wpcli service (compose.yml). Runs as www-data (33) in /var/www/html.
#
#   install             WordPress + WooCommerce + Affiliate Captain (WPAC_PLUGIN=checkout|release)
#   update-to-checkout  replace the installed plugin (e.g. release 0.15.0) with this checkout
#   use-release         replace the plugin with the release ZIP (WPAC_RELEASE_URL)
#   anything else       passed to wp-cli, e.g. "wp wpac health" or "plugin list"
set -eu

PLUGIN_DIR=wp-content/plugins/affiliate-captain
CHECKOUT=/opt/affiliate-captain

wait_for_wordpress() {
	# The Apache container copies WordPress into the shared volume and writes wp-config.php on
	# its first start; the database must accept connections.
	i=0
	# (mysqli instead of "wp db check": the MariaDB client of the CLI image insists on TLS, which
	# older servers such as MariaDB 10.11 in Docker do not offer.)
	until [ -f wp-config.php ] && php -r 'exit( @mysqli_connect( getenv( "WORDPRESS_DB_HOST" ), getenv( "WORDPRESS_DB_USER" ), getenv( "WORDPRESS_DB_PASSWORD" ), getenv( "WORDPRESS_DB_NAME" ) ) ? 0 : 1 );' 2>/dev/null; do
		i=$((i + 1))
		if [ "$i" -gt 90 ]; then
			echo "WordPress or the database did not come up within 3 minutes." >&2
			exit 1
		fi
		sleep 2
	done
}

use_checkout() {
	[ -f "$CHECKOUT/affiliate-captain.php" ] || { echo "No checkout mounted at $CHECKOUT" >&2; exit 1; }
	rm -rf "$PLUGIN_DIR"
	ln -s "$CHECKOUT" "$PLUGIN_DIR"
	echo "Plugin: checkout ($(sed -n 's/^ \* Version: *//p' "$CHECKOUT/affiliate-captain.php"))"
}

use_release() {
	rm -rf "$PLUGIN_DIR"
	wp plugin install "$WPAC_RELEASE_URL" --force
	echo "Plugin: release ZIP $WPAC_RELEASE_URL"
}

case "${1:-install}" in
install)
	wait_for_wordpress
	if ! wp core is-installed 2>/dev/null; then
		wp core install --url="$WPAC_URL" --title="Affiliate Captain Staging" \
			--admin_user=admin --admin_password=admin --admin_email=admin@example.test --skip-email
		wp rewrite structure '/%postname%/'
		# German admin and documents; needs downloads.wordpress.org (skipped when unreachable).
		wp language core install de_DE --activate || echo "Language pack not installed (no network?)"
	fi
	mkdir -p wp-content/mu-plugins
	cp /opt/wpac-staging/mu-plugins/wpac-staging-mail.php wp-content/mu-plugins/

	if ! wp plugin is-installed woocommerce; then
		# WPAC_WOO_SOURCE: ZIP path or URL (e.g. a ZIP placed in tests/staging/ is
		# /opt/wpac-staging/<name>.zip); otherwise wordpress.org, optionally WPAC_WOO_VERSION.
		if [ -n "${WPAC_WOO_SOURCE:-}" ]; then
			wp plugin install "$WPAC_WOO_SOURCE" --activate || true
		elif [ -n "${WPAC_WOO_VERSION:-}" ]; then
			wp plugin install woocommerce --version="$WPAC_WOO_VERSION" --activate || true
		else
			wp plugin install woocommerce --activate || true
		fi
		if wp plugin is-active woocommerce; then
			wp language plugin install woocommerce de_DE || true
			# Shop in Germany, EUR; cash on delivery (order "processing" at once) and bank
			# transfer (order "on hold" until it is marked as paid).
			wp option update woocommerce_default_country 'DE:DE-BE'
			wp option update woocommerce_store_address 'Teststraße 1'
			wp option update woocommerce_store_city 'Berlin'
			wp option update woocommerce_store_postcode '10115'
			wp option update woocommerce_currency 'EUR'
			wp option update woocommerce_onboarding_profile '{"skipped":true}' --format=json
			wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Nachnahme"}' --format=json
			wp option update woocommerce_bacs_settings '{"enabled":"yes","title":"Überweisung"}' --format=json
			wp wc tool run install_pages --user=admin >/dev/null 2>&1 || true
			wp wc product create --name='Testprodukt' --type=simple --regular_price=100 --user=admin --porcelain
		else
			echo "WARNING: WooCommerce could not be installed (no connection to wordpress.org?)." >&2
			echo "         Set WPAC_WOO_SOURCE to a WooCommerce ZIP and run the install again." >&2
		fi
	fi

	if [ ! -e "$PLUGIN_DIR" ]; then
		if [ "${WPAC_PLUGIN:-checkout}" = "release" ]; then
			use_release
		else
			use_checkout
		fi
	fi
	wp plugin activate affiliate-captain
	wp plugin list --fields=name,status,version
	echo
	echo "Ready: $WPAC_URL/wp-admin/  (admin / admin)   Mail: $WPAC_MAIL_URL"
	;;
update-to-checkout)
	wait_for_wordpress
	echo "Before: $(wp plugin get affiliate-captain --field=version)"
	# Like an update: the files are replaced while the plugin stays active; the plugin runs its
	# migrations on the next request (schema version in the option wpac_db_version).
	use_checkout
	wp plugin list --fields=name,status,version
	;;
use-release)
	wait_for_wordpress
	use_release
	wp plugin activate affiliate-captain
	wp plugin list --fields=name,status,version
	;;
wp)
	shift
	exec wp "$@"
	;;
*)
	exec wp "$@"
	;;
esac
