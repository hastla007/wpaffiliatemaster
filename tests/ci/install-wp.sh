#!/usr/bin/env bash
# Builds a throw-away WordPress + WooCommerce test site for the browser and cross-plugin tests in CI.
#   tests/ci/install-wp.sh <wp-dir> [--siblings <dir-with-voucher-and-pass-captain>]
# Needs: php (mysqli), curl, unzip, a database (WPAC_DB_* env vars).
set -euo pipefail
WP_DIR="${1:?wp dir}"; shift
SIBLINGS=""
if [ "${1:-}" = "--siblings" ]; then SIBLINGS="${2:?siblings dir}"; fi
PLUGIN_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
# Plugin code to install (default: this checkout; the upgrade test installs the previous release first).
PLUGIN_SRC="$(cd "${WPAC_PLUGIN_SRC:-$PLUGIN_DIR}" && pwd)"
DB_NAME="${WPAC_DB_NAME:-wpac_e2e}"; DB_USER="${WPAC_DB_USER:-root}"; DB_PASS="${WPAC_DB_PASSWORD:-root}"; DB_HOST="${WPAC_DB_HOST:-127.0.0.1}"
URL="${WP_URL:-http://127.0.0.1:8088}"

mkdir -p "$WP_DIR"
if [ ! -f "$WP_DIR/wp-load.php" ]; then
  # WP_VERSION: "latest" (default) or a release such as 6.3 (minimum-version job).
  if [ -z "${WP_VERSION:-}" ] || [ "${WP_VERSION}" = "latest" ]; then WP_TGZ="latest.tar.gz"; else WP_TGZ="wordpress-${WP_VERSION}.tar.gz"; fi
  curl -fsSL "https://wordpress.org/${WP_TGZ}" | tar xz -C "$WP_DIR" --strip-components=1
fi
if [ ! -d "$WP_DIR/wp-content/plugins/woocommerce" ]; then
  curl -fsSL -o /tmp/woocommerce.zip "https://downloads.wordpress.org/plugin/woocommerce.${WC_VERSION:-latest-stable}.zip"
  unzip -q /tmp/woocommerce.zip -d "$WP_DIR/wp-content/plugins"
fi
# Folder = slug: "affiliate-captain" since 0.11, "wp-affiliate-captain" for older releases (upgrade test).
SLUG="affiliate-captain"
if [ ! -f "$PLUGIN_SRC/affiliate-captain.php" ] && [ -f "$PLUGIN_SRC/wp-affiliate-captain.php" ]; then SLUG="wp-affiliate-captain"; fi
ln -sfn "$PLUGIN_SRC" "$WP_DIR/wp-content/plugins/$SLUG"
if [ -n "$SIBLINGS" ]; then
  ln -sfn "$SIBLINGS/wp-couponmaster" "$WP_DIR/wp-content/plugins/voucher-captain"
  ln -sfn "$SIBLINGS/wp-passmaster" "$WP_DIR/wp-content/plugins/wp-pass-captain"
fi
cat > "$WP_DIR/wp-config.php" <<CFG
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_HOME', '$URL' );
define( 'WP_SITEURL', '$URL' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
${WPAC_EXTRA_CONFIG:-}
\$table_prefix = 'wp_';
foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as \$k ) { define( \$k, 'ci-' . \$k ); }
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG
php "$PLUGIN_DIR/tests/ci/setup.php" "$WP_DIR" "$URL" ${SIBLINGS:+--siblings}
