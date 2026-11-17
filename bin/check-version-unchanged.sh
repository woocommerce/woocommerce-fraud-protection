#!/usr/bin/env bash

# Fails when any plugin version location differs between two commits.
# Only release pull requests (release/<version> branches) may change the version.

set -euo pipefail
shopt -s inherit_errexit

base_ref=${1:?Provide the base commit.}
head_ref=${2:?Provide the head commit.}

# Fails closed when a version location can no longer be read, so a format change
# can't make both sides empty and pass the comparison.
print_version() {
	local ref=$1 label=$2 value=$3

	if [[ -z $value || $value == null ]]; then
		echo "::error::Could not read the plugin version from $label at $ref." >&2
		exit 1
	fi

	echo "$label: $value"
}

read_versions() {
	local ref=$1 value

	value=$(git show "$ref:package.json" | jq -r '.version')
	print_version "$ref" 'package.json' "$value"

	value=$(git show "$ref:package-lock.json" | jq -r '.version')
	print_version "$ref" 'package-lock.json' "$value"

	value=$(git show "$ref:package-lock.json" | jq -r '.packages[""].version')
	print_version "$ref" 'package-lock.json packages[""]' "$value"

	value=$(git show "$ref:woocommerce-fraud-protection.php" | sed -n 's/^ \* Version: //p')
	print_version "$ref" 'woocommerce-fraud-protection.php' "$value"

	value=$(git show "$ref:src/Internal/FraudProtectionPlugin/PluginInitializer.php" | sed -n "s/.*define( 'WC_FRAUD_PROTECTION_VERSION', '\([^']*\)' ).*/\1/p")
	print_version "$ref" 'PluginInitializer.php' "$value"
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
