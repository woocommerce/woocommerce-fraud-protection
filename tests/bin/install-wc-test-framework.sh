#!/bin/bash

set -e

if [ "$#" -lt 2 ]; then
	echo "Usage: $0 <woocommerce-plugin-dir> <woocommerce-version>"
	exit 1
fi

WC_PLUGIN_DIR=$1
WC_VERSION=$2

if [ -f "$WC_PLUGIN_DIR/tests/legacy/framework/class-wc-unit-test-case.php" ]; then
	exit 0
fi

SOURCE_DIR=$(mktemp -d)
trap 'rm -rf "$SOURCE_DIR"' EXIT

echo "Overlaying WooCommerce $WC_VERSION test framework from source..."
git clone --quiet --depth 1 --branch "$WC_VERSION" --filter=blob:none --sparse \
	https://github.com/woocommerce/woocommerce.git "$SOURCE_DIR"
git -C "$SOURCE_DIR" sparse-checkout set plugins/woocommerce/tests
cp -r "$SOURCE_DIR/plugins/woocommerce/tests" "$WC_PLUGIN_DIR/"
