#!/bin/bash

set -e

PROJECT_DIR=$(cd "$(dirname "$0")/.." && pwd)
CONFIG_FILE="$PROJECT_DIR/.wp-env.phpunit.json"
PLUGIN_DIR="wp-content/plugins/$(basename "$PROJECT_DIR")"
WP_ENV="$PROJECT_DIR/node_modules/.bin/wp-env"

if [ ! -x "$WP_ENV" ]; then
	echo "wp-env is unavailable. Run 'npm install' first."
	exit 1
fi

"$WP_ENV" start --config="$CONFIG_FILE"

"$WP_ENV" run cli --config="$CONFIG_FILE" --env-cwd="$PLUGIN_DIR" bash -c '
	if [ ! -f /var/www/html/wp-content/plugins/woocommerce/tests/legacy/framework/class-wc-unit-test-case.php ]; then
		command -v git >/dev/null || sudo apk add --no-cache git
	fi
	WC_VERSION=$(wp plugin get woocommerce --field=version)
	tests/bin/install-wc-test-framework.sh /var/www/html/wp-content/plugins/woocommerce "$WC_VERSION"
'

"$WP_ENV" run cli --config="$CONFIG_FILE" --env-cwd="$PLUGIN_DIR" vendor/bin/phpunit "$@"
