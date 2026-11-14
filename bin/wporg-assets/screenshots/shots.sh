#!/usr/bin/env bash
# Takes the WordPress.org screenshots (.wordpress-org/screenshot-1.png … -8.png) from a demo site.
#
# Demo site (once): WordPress (no WooCommerce needed; sales come in through the contract API with the
# source "course-booking"), this plugin linked into wp-content/plugins/affiliate-captain, a wp-config.php
# with WP_HOME/WP_SITEURL http://harbor-sailing.example.com and an empty database, a mu-plugin with
# add_filter( 'pre_wp_mail', '__return_true' ), then:
#   php bin/wporg-assets/screenshots/install.php <wp-dir> && php bin/wporg-assets/screenshots/data.php <wp-dir>
#   (cd <wp-dir> && php -S 127.0.0.1:80 -t . <plugin>/tests/ci/router.php)
# The browser maps harbor-sailing.example.com to 127.0.0.1 (see shot.js). Logins: admin / admin-pass-123,
# partner lena / lena-pass-123. All names, e-mail addresses and IBANs are fake (example.com, test IBANs).
#
# Afterwards: python3 bin/wporg-assets/optimize.py
set -euo pipefail
cd "$(dirname "$0")"
OUT=../../../.wordpress-org
FROM=$(date -d '-89 days' +%F); TO=$(date +%F)

# 1 Dashboard (last 90 days).
node shot.js "$OUT/screenshot-1.png" "/wp-admin/admin.php?page=wpac&from=$FROM&to=$TO" 1600 770
# 2 Partner list.
node shot.js "$OUT/screenshot-2.png" "/wp-admin/admin.php?page=wpac-affiliates" 1600 1000
# 3 Partner details, account statement.
node shot.js "$OUT/screenshot-3.png" "/wp-admin/admin.php?page=wpac-affiliates&action=edit&id=1&tab=balance" 1600 950
# 4 Commission rules.
node shot.js "$OUT/screenshot-4.png" "/wp-admin/admin.php?page=wpac-rules" 1600 1000
# 5 Payout run preview and payouts.
node shot.js "$OUT/screenshot-5.png" "/wp-admin/admin.php?page=wpac-payouts&preview=1" 1600 1000
# 6 Partner area (logged in as the partner).
CLIP=0,0,1280,990 node shot.js "$OUT/screenshot-6.png" "/partner-area/" 1280 1000 0 lena lena-pass-123
# 7 Application form block (logged out, filled in).
FILL='[name=wpac_name]|Emma Lindqvist;[name=wpac_email]|emma@sailing-diaries.example.com;[name=wpac_website]|https://sailing-diaries.example.com;[name=wpac_channel]|Blog and YouTube;[name=wpac_note]|Course reviews and a monthly newsletter about learning to sail.;[name=wpac_f_channel_url]|https://video.example.com/@sailingdiaries;[name=wpac_f_audience]|12000' \
EVAL="document.querySelector('[name=wpac_terms]').checked=true; document.activeElement.blur();" \
CLIP=0,0,1280,1240 node shot.js "$OUT/screenshot-7.png" "/become-a-partner/?wpac_group=2" 1280 1300 0 -
# 8 CSV import check.
node import.js "$OUT/screenshot-8.png" partners.csv 700
