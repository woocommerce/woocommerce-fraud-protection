#!/usr/bin/env bash

set -euo pipefail

repository_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
release_archive=${1:?Provide the release archive.}
qit_archive=${2:?Provide the QIT archive path.}
plugin_root=woocommerce-fraud-protection
temporary_dir=$(mktemp -d)

cleanup() {
	rm -rf "$temporary_dir"
}

trap cleanup EXIT

if [[ $release_archive != /* ]]; then
	release_archive="$PWD/$release_archive"
fi

if [[ $qit_archive != /* ]]; then
	qit_archive="$PWD/$qit_archive"
fi

unzip -q "$release_archive" -d "$temporary_dir"
cp "$repository_dir/composer.json" "$temporary_dir/$plugin_root/composer.json"
cp "$repository_dir/composer.lock" "$temporary_dir/$plugin_root/composer.lock"
cp "$repository_dir/package-lock.json" "$temporary_dir/$plugin_root/package-lock.json"

(cd "$temporary_dir" && zip -qr "$qit_archive" "$plugin_root")

for required_file in composer.json composer.lock package.json package-lock.json; do
	unzip -p "$qit_archive" "$plugin_root/$required_file" >/dev/null
done

echo "Created QIT archive at $qit_archive."
