#!/bin/bash
#
# Seed the wp-env dev site for WooCommerce Fraud Protection.
#
# Idempotent: it records a `wcfp_env_initialized` option and skips on re-run,
# so it is safe to wire into `postenv:start`. Runs against the `cli` (dev)
# environment. Under `set -e`, any failed step aborts the script before the
# option is stamped, so the next start retries the seed.

set -e

# The plugin bails (no wiring, just a log line) unless vendor/autoload.php is
# present, because it loads its PSR-4 autoloader from there. vendor/ is
# gitignored, so a fresh worktree has none.
if [ ! -f "vendor/autoload.php" ]; then
	echo
	echo "WARNING: vendor/autoload.php is missing. Fraud Protection will not wire up."
	echo "         Run 'composer install' in this worktree, then 'npm run env:start' again."
	echo
fi

INITIALIZED=$(wp-env run cli wp option get wcfp_env_initialized 2>/dev/null || echo "")
if [ "$INITIALIZED" == "yes" ]; then
	echo "- Store already configured for Fraud Protection. Skipping."
	exit 0
fi

echo "Setting up Woo store for WooCommerce Fraud Protection"

# Pretty permalinks (required for the REST API and block checkout).
wp-env run cli wp rewrite structure '/%postname%/' --hard

# Install WooCommerce pages and set a US store address.
wp-env run cli wp wc tool run install_pages --user=1
wp-env run cli wp option update woocommerce_currency "USD"
wp-env run cli wp option update woocommerce_store_address "166 Crosby Street"
wp-env run cli wp option update woocommerce_store_city "New York"
wp-env run cli wp option update woocommerce_default_country "US:NY"
wp-env run cli wp option update woocommerce_store_postcode "10012"
wp-env run cli wp option update woocommerce_allow_tracking "no"
wp-env run cli wp option update woocommerce_coming_soon "no"
wp-env run cli wp option update woocommerce_task_list_reminder_bar_hidden yes

# Use the storefront theme.
wp-env run cli wp theme activate storefront

# Enable the dummy payment gateway so checkout can complete without a real PSP.
# Idempotent by nature (re-enabling an enabled gateway succeeds); a failure
# here is real (e.g. the gateway plugin is missing) and must abort the seed.
wp-env run cli wp wc payment_gateway update dummy --enabled=true --user=1

# A simple product to buy. Guard on the slug so a retried run after a partial
# failure (before the init flag is set below) does not create a duplicate.
if [ -z "$(wp-env run cli wp post list --post_type=product --name=fp-test-product --field=ID 2>/dev/null | tr -d '[:space:]')" ]; then
	wp-env run cli wp wc product create -- --name="Fraud Protection Test Product" --slug="fp-test-product" --user=1 --regular_price=10 --type=simple --virtual=true
fi

# A customer account for testing the logged-in flow. Guarded like the product
# above: skip only when the user verifiably exists, so a retried run does not
# fail on "already registered" and a real create failure still aborts.
if [ -z "$(wp-env run cli wp user get customer --field=ID 2>/dev/null | tr -d '[:space:]')" ]; then
	wp-env run cli wp user create customer customer@example.com --user_pass=password --role=customer
fi

# Mark as initialized so this script is a no-op on the next start. Reaching
# this line means every step above succeeded (set -e, no masked failures).
wp-env run cli wp option update wcfp_env_initialized yes

# Report the URL the site actually runs on: with a port override
# (.wp-env.override.json or WP_ENV_PORT) it is not the default 8888.
SITE_URL=$(wp-env run cli wp option get siteurl 2>/dev/null | tr -d '[:space:]')
SITE_URL=${SITE_URL:-http://localhost:8888}

echo
echo "- Setup complete. Site: ${SITE_URL}  (admin: ${SITE_URL}/wp-admin, admin/password)"
