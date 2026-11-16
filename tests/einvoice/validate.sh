#!/usr/bin/env bash
# Validates the sample e-invoices (tests/einvoice/generate.php) with the official KoSIT validator
# and the XRechnung validator configuration, both at pinned versions checked against SHA-256
# checksums before use (a changed or replaced release asset fails the run).
#
#   tests/einvoice/validate.sh <samples dir> <work dir>
#
# Rulesets: the XRechnung configuration has a scenario per specification identifier (BT-24).
# XRechnung 3.0 samples run through "EN16931 XRechnung (CII)" (CII D16B XML schema, EN 16931 CII
# schematron, XRechnung CII schematron); EN 16931 core profile samples run through
# "EN16931 (CII)" (CII D16B XML schema, EN 16931 CII schematron) of the same configuration.
# check-reports.php fails on any error-level finding, on a rejected document and on a sample
# checked by an unexpected scenario. Requires Java 11+, curl, unzip, sha256sum and PHP.
#
# Updating: change the version, download the asset once and put its `sha256sum` here.
set -euo pipefail

VALIDATOR_VERSION="1.6.3"
VALIDATOR_SHA256="95d5a399ff5186dae5ddb7edd9a14a9c8352610159a90bfa27d4739a8bc37822"
CONFIG_TAG="v2026-08-31"
CONFIG_ZIP="xrechnung-3.0.2-validator-configuration-2026-08-31.zip"
CONFIG_SHA256="2530cd107c414511c5d0462ec10f886910395abfca820db82e83d70bf01221a8"

samples="${1:?samples directory}"
work="${2:?work directory}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
mkdir -p "$work/download" "$work/validator" "$work/config" "$work/reports"

fetch() { # url, file, sha256
  if [ ! -f "$2" ] || ! echo "$3  $2" | sha256sum -c --quiet - >/dev/null 2>&1; then
    curl -fsSL --retry 3 -o "$2" "$1"
  fi
  echo "$3  $2" | sha256sum -c --quiet - || { echo "Checksum mismatch: $1" >&2; exit 1; }
}
fetch "https://github.com/itplr-kosit/validator/releases/download/v${VALIDATOR_VERSION}/validator-${VALIDATOR_VERSION}.zip" \
  "$work/download/validator.zip" "$VALIDATOR_SHA256"
fetch "https://github.com/itplr-kosit/validator-configuration-xrechnung/releases/download/${CONFIG_TAG}/${CONFIG_ZIP}" \
  "$work/download/config.zip" "$CONFIG_SHA256"
unzip -qo "$work/download/validator.zip" -d "$work/validator"
unzip -qo "$work/download/config.zip" -d "$work/config"
java -version 2>&1 | grep -v JAVA_TOOL_OPTIONS | head -1

# The validator exits non-zero when a document is rejected; the reports are evaluated in any case
# so the log shows every finding.
status=0
java -cp "$work/validator/validator-${VALIDATOR_VERSION}.jar:$work/validator/libs/*" \
  de.kosit.validationtool.cmd.CommandLineApplication \
  -s "$work/config/scenarios.xml" -r "$work/config" -o "$work/reports" -h "$samples"/*.xml || status=$?
php "$here/check-reports.php" "$samples" "$work/reports" || status=1
exit "$status"
