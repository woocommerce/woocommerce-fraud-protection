#!/usr/bin/env bash

# Fails when any plugin version location differs between two commits.
# Only release pull requests (release/<version> branches) may change the version.

set -euo pipefail

base_ref=${1:?Provide the base commit.}
head_ref=${2:?Provide the head commit.}

read_versions() {
	local ref=$1

	echo "package.json: $(git show "$ref:package.json" | jq -r '.version')"
	echo "package-lock.json: $(git show "$ref:package-lock.json" | jq -r '.version')"
	echo "package-lock.json packages[\"\"]: $(git show "$ref:package-lock.json" | jq -r '.packages[""].version')"
	echo "woocommerce-fraud-protection.php: $(git show "$ref:woocommerce-fraud-protection.php" | sed -n 's/^ \* Version: //p')"
	echo "PluginInitializer.php: $(git show "$ref:src/Internal/FraudProtectionPlugin/PluginInitializer.php" | sed -n "s/.*define( 'WC_FRAUD_PROTECTION_VERSION', '\([^']*\)' ).*/\1/p")"
}

base_versions=$(read_versions "$base_ref")
head_versions=$(read_versions "$head_ref")

if [[ $base_versions != "$head_versions" ]]; then
	echo "::error::The plugin version changed. Only release/<version> pull requests may change it."
	diff <(echo "$base_versions") <(echo "$head_versions") || true
	exit 1
fi

echo "Plugin version unchanged:"
echo "$head_versions"
