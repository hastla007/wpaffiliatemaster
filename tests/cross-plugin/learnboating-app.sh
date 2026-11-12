#!/bin/bash
# Gesamttest, Teil LearnBoating: App-Link (Mobile-API) → Shop → Kauf ohne Cookie → Zuordnung "app".
# Nur gegen eine TEST-Installation mit WP Affiliate Captain, LearnBoating und dem LearnBoating-Connector.
#
#   WP_ROOT=/pfad/zu/wordpress WP_URL=http://127.0.0.1:8088 LB_USER=kunde LB_PASS=geheim \
#     bash tests/cross-plugin/learnboating-app.sh PARTNERCODE
set -u
WP=${WP_ROOT:?WP_ROOT setzen}
B=${WP_URL:?WP_URL setzen}
CODE=${1:?Partnercode als Argument}
LB_USER=${LB_USER:?LB_USER setzen}; LB_PASS=${LB_PASS:?LB_PASS setzen}
S=$(mktemp -d)
FAIL=0
J=$S/jar.txt
TOKEN=$(curl -s -X POST -H 'Content-Type: application/json' -d "{\"email\":\"$LB_USER\",\"password\":\"$LB_PASS\",\"device_name\":\"cross-plugin-test\"}" $B/wp-json/boatingschool/v1/auth/login | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])")
SLUG=$(php -r "\$_SERVER['HTTP_HOST']='${B#*://}'; require '$WP/wp-load.php'; echo Bootspruefung\\Learning\\Mobile\\MobileSettings::tenantSlug();" 2>/dev/null)
COURSES=$(curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Slug: $SLUG" "$B/wp-json/boatingschool/v1/courses")
CID=$(echo "$COURSES" | python3 -c "import sys,json; d=json.load(sys.stdin); d=d.get('data',d); print(d[0]['id'] if isinstance(d,list) else '')")
echo "Kurs-ID aus der App: $CID"
DETAIL=$(curl -s -H "Authorization: Bearer $TOKEN" -H "X-Tenant-Slug: $SLUG" -H "X-Partner-Code: $CODE" "$B/wp-json/boatingschool/v1/courses/$CID")
URL=$(echo "$DETAIL" | python3 -c "import sys,json; d=json.load(sys.stdin); d=d.get('data',d); print(d.get('webshop_url') or '')")
echo "Kaufangebot aus der App: $URL"
case "$URL" in *"partner=$CODE"*utm_campaign=app*|*utm_campaign=app*"partner=$CODE"*) echo "  ok   App hängt Partnercode und Kampagne an";; *) echo "  FAIL App-Link ohne Partnercode"; FAIL=1;; esac
# Shop: Produktseite mit Partnerlink + in den Warenkorb (ohne Marketing-Einwilligung, kein Cookie)
PID=$(php -r "\$_SERVER['HTTP_HOST']='${B#*://}'; require '$WP/wp-load.php'; echo url_to_postid('$URL') ?: wc_get_product_id_by_sku('BP-SBF-SEE');" 2>/dev/null)
SEP='&'; case "$URL" in *\?*) ;; *) SEP='?';; esac
curl -s -c "$J" -b "$J" -o /dev/null "$URL${SEP}add-to-cart=$PID"
NONCE=$(curl -s -c "$J" -b "$J" -D - -o /dev/null "$B/wp-json/wc/store/v1/cart" | tr -d '\r' | awk -F': ' 'tolower($1)=="nonce"{print $2}')
echo "Warenkorb: $(curl -s -c "$J" -b "$J" "$B/wp-json/wc/store/v1/cart" | python3 -c "import sys,json; print([i['name'] for i in json.load(sys.stdin)['items']])")"
grep -q "wpac_ref" "$J" && { echo "  FAIL Affiliate-Cookie ohne Einwilligung gesetzt"; FAIL=1; } || echo "  ok   kein Affiliate-Cookie gesetzt"
EMAIL="e2e-app-$(date +%s)@example.de"
RES=$(curl -s -c "$J" -b "$J" -X POST -H "Nonce: $NONCE" -H 'Content-Type: application/json' "$B/wp-json/wc/store/v1/checkout" -d '{
 "billing_address":{"first_name":"App","last_name":"Kundin","address_1":"Hafen 1","city":"Kiel","postcode":"24103","country":"DE","email":"'"$EMAIL"'"},
 "shipping_address":{"first_name":"App","last_name":"Kundin","address_1":"Hafen 1","city":"Kiel","postcode":"24103","country":"DE"},
 "payment_method":"bacs", "create_account":true, "customer_password":"E2e-app-kundin-Pass-2026!",
 "additional_fields":{"bootspruefung/digital-consent":true}}')
OID=$(echo "$RES" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('order_id') or ('ERR '+d.get('message','')))")
echo "Bestellung: $OID"
cat > $S/check.php <<EOF
<?php
\$_SERVER['HTTP_HOST'] = '${B#*://}';
require '$WP/wp-load.php';
wp_set_current_user( 1 );
\$o = wc_get_order( (int) '$OID' ); \$o->payment_complete(); // Überweisung eingegangen
global \$wpdb; \$p = \$wpdb->prefix;
\$a = \$wpdb->get_row( \$wpdb->prepare( "SELECT method, campaign, consent, affiliate_id FROM {\$p}wpac_attributions WHERE source='woocommerce' AND source_order_id=%s", '$OID' ), ARRAY_A );
\$c = \$wpdb->get_results( \$wpdb->prepare( "SELECT description, base_minor, amount_minor, hold_until FROM {\$p}wpac_commissions WHERE source='woocommerce' AND source_order_id=%s", '$OID' ), ARRAY_A );
echo 'Zuordnung: ', json_encode( \$a ), "\n", 'Provision: ', json_encode( \$c ), "\n";
echo ( \$a && 'link' === \$a['method'] && 'app' === \$a['campaign'] && '0' === (string) \$a['consent'] ) ? "  ok   Kauf über den App-Link zugeordnet (Methode link, Kampagne app, ohne Cookie)\n" : "  FAIL Zuordnung falsch\n";
echo ( \$c && strtotime( \$c[0]['hold_until'] ) > time() + 10 * DAY_IN_SECONDS ) ? "  ok   Provision bis nach Garantie/Widerruf gesperrt\n" : "  FAIL Haltefrist\n";
echo array() === WPAffiliateCaptain\Commissions\Balance::verify_all() ? "  ok   Affiliate-Konten konsistent\n" : "  FAIL Affiliate-Konten\n";
EOF
OUT=$(php $S/check.php 2>&1 | grep -v Deprecated); echo "$OUT"
echo "$OUT" | grep -q FAIL && FAIL=1
rm -rf -- "$S"
exit $FAIL
