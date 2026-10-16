#!/usr/bin/env bash
#
# Runs every automated check in this repository.
# Exits non-zero if any of them fail.
#
#   ./run-all-checks.sh
#
# Requires: PHP 8+, Node 22.22+ or 24.8+, and `npm install` having been run once.

set -uo pipefail
cd "$(dirname "$0")"

status=0

run() {
    local label="$1"; shift
    echo
    echo "────────────────────────────────────────────────────────────"
    echo "  $label"
    echo "────────────────────────────────────────────────────────────"
    if "$@"; then
        echo "  ✓ $label"
    else
        echo "  ✗ $label FAILED"
        status=1
    fi
}

echo "Environment:"
echo "  php  $(php  --version | head -1 | cut -d' ' -f2)"
echo "  node $(node --version)"

# --- Q1: PHP ----------------------------------------------------------------
run "Q1 · PHP syntax" bash -c '
    php -l 01-php/fixed.php   > /dev/null &&
    php -l 01-php/helpers.php > /dev/null &&
    echo "  no syntax errors"'

run "Q1 · original fault evidence" php 01-php/tests/verify-original-faults.php
run "Q1 · corrected template checks" php 01-php/tests/run-checks.php

# --- Q4: CSS ----------------------------------------------------------------
run "Q4 · layout checks" node 04-css/tests/run-checks.cjs
run "Q4 · markup validity" npx --no-install html-validate 04-css/index.html

# --- Q5: HTML ---------------------------------------------------------------
run "Q5 · corrected markup validity" npx --no-install html-validate 05-html/index.html
run "Q5 · original markup is invalid (expected)" bash -c '
    if npx --no-install html-validate 05-html/broken.html > /dev/null 2>&1; then
        echo "  unexpected: broken.html validated clean"; exit 1
    else
        echo "  broken.html fails validation, as expected"
    fi'

# --- Q6: JavaScript ---------------------------------------------------------
run "Q6 · behaviour checks" node 06-js/tests/run-checks.cjs
run "Q6 · markup validity" npx --no-install html-validate 06-js/index.html

# --- Q9: newsletter ---------------------------------------------------------
run "Q9 · PHP syntax" bash -c 'php -l 09-newsletter/subscribe.php > /dev/null && echo "  no syntax errors"'
run "Q9 · idempotency checks" php 09-newsletter/tests/run-checks.php

# --- summary ----------------------------------------------------------------
echo
echo "════════════════════════════════════════════════════════════"
if [ "$status" -eq 0 ]; then
    echo "  ALL CHECKS PASSED"
else
    echo "  SOME CHECKS FAILED"
fi
echo "════════════════════════════════════════════════════════════"

exit "$status"
