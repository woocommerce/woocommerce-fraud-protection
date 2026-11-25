#!/usr/bin/env bash

# Packages each example plugin under examples/ into an installable zip in the repository root.

set -euo pipefail

repository_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
examples_dir="$repository_dir/examples"

for plugin_dir in "$examples_dir"/*/; do
	slug=$(basename "$plugin_dir")
	archive="$repository_dir/$slug.zip"
	main_file="$slug/$slug.php"

	if [[ ! -f "$examples_dir/$main_file" ]]; then
		echo "Skipping $slug: it has no $slug.php main plugin file." >&2
		continue
	fi

	rm -f "$archive"
	(cd "$examples_dir" && zip -qr "$archive" "$slug" -x '*/.DS_Store')

	unzip -p "$archive" "$main_file" >/dev/null

	echo "Created $slug.zip."
done
