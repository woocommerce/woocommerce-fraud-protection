#!/usr/bin/env bash

set -euo pipefail

repository_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
version=${1:?Provide the release version.}
archive_path=${2:-}

if [[ ! $version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release version: $version" >&2
	exit 1
fi

release_branch=${RELEASE_BRANCH:-${GITHUB_REF_NAME:-$(git -C "$repository_dir" symbolic-ref --quiet --short HEAD || true)}}

if [[ $release_branch != "release/$version" ]]; then
	echo "Release branch mismatch: expected release/$version, found ${release_branch:-detached HEAD}" >&2
	exit 1
fi

versions=(
	"$(node -p "require('$repository_dir/package.json').version")"
	"$(node -p "require('$repository_dir/package-lock.json').version")"
	"$(node -p "require('$repository_dir/package-lock.json').packages[''].version")"
	"$(sed -n 's/^ \* Version: //p' "$repository_dir/woocommerce-fraud-protection.php")"
	"$(sed -n "s/.*define( 'WC_FRAUD_PROTECTION_VERSION', '\([^']*\)' ).*/\1/p" "$repository_dir/src/Internal/FraudProtectionPlugin/PluginInitializer.php")"
)

for found_version in "${versions[@]}"; do
	if [[ $found_version != "$version" ]]; then
		echo "Release version mismatch: expected $version, found $found_version" >&2
		exit 1
	fi
done

if ! grep -Eq "^[0-9]{4}-[0-9]{2}-[0-9]{2} - version $version$" "$repository_dir/changelog.txt"; then
	echo "Changelog has no dated entry for version $version." >&2
	exit 1
fi

extract_release_notes() {
	awk -v version="$version" '
		$0 ~ "^[0-9]{4}-[0-9]{2}-[0-9]{2} - version " version "$" {
			found = 1
			next
		}

		found && /^$/ {
			exit
		}

		found {
			print
			entries++
		}

		END {
			if ( ! found || ! entries ) {
				exit 1
			}
		}
	' "$repository_dir/changelog.txt"
}

if ! extract_release_notes > "$repository_dir/release-notes.txt"; then
	echo "Changelog has no release notes for version $version." >&2
	exit 1
fi

if [[ -n $archive_path ]]; then
	if [[ $archive_path != /* ]]; then
		archive_path="$PWD/$archive_path"
	fi

	unzip -t "$archive_path" >/dev/null

	if zipinfo -1 "$archive_path" | awk '! /^woocommerce-fraud-protection\// { found = 1 } END { exit ! found }'; then
		echo "Release archive contains a path outside the plugin root." >&2
		exit 1
	fi

	if zipinfo -1 "$archive_path" | grep -E '^woocommerce-fraud-protection/(package\.json|README\.md)$' >/dev/null; then
		echo "Release archive contains files excluded from production." >&2
		exit 1
	fi

	unzip -p "$archive_path" woocommerce-fraud-protection/vendor/autoload.php >/dev/null

	packaged_version=$(unzip -p "$archive_path" woocommerce-fraud-protection/woocommerce-fraud-protection.php | sed -n 's/^ \* Version: //p')
	packaged_changelog=$(unzip -p "$archive_path" woocommerce-fraud-protection/changelog.txt | sed -n '3p')

	if [[ $packaged_version != "$version" || ! $packaged_changelog =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ -\ version\ $version$ ]]; then
		echo "Release archive metadata does not match version $version." >&2
		exit 1
	fi
fi

echo "Validated release version $version."
