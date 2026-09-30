#!/usr/bin/env bash
#
# Build a wp.org-ready distribution of Kudora – Coupons, Gift Cards & Loyalty for WooCommerce.
#
# Produces:
#   dist/kudora-coupons-gift-cards-loyalty-for-woocommerce/       staged clean plugin folder
#   dist/kudora-coupons-gift-cards-loyalty-for-woocommerce.zip    installable package
#
# Everything listed in .distignore (dev-only files) is excluded, so the package
# contains no hidden or unexpected files and passes WordPress Plugin Check.
set -euo pipefail

SLUG="kudora-coupons-gift-cards-loyalty-for-woocommerce"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/dist"
STAGE="$OUT/$SLUG"

cd "$ROOT"

# 1. Stage a clean copy (a trailing slash on the source copies its contents).
rm -rf "$OUT"
mkdir -p "$STAGE"
rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$STAGE/"

# 2. Zip the staged folder (so the archive has the slug as its top-level dir).
( cd "$OUT" && zip -rqX "$SLUG.zip" "$SLUG" )

echo "Built $OUT/$SLUG.zip"
