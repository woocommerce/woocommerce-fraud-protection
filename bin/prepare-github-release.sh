#!/usr/bin/env bash

set -euo pipefail

version=${1:?Provide the release version.}
release_sha=${2:?Provide the tested release commit.}
archive_path=${3:?Provide the release archive.}
notes_path=${4:?Provide the release notes.}
repository=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required.}
tag="v$version"
asset_name=woocommerce-fraud-protection.zip

resolve_tag_commit() {
	local object
	local object_sha
	local object_type
	local depth=0

	object=$(gh api "repos/$repository/git/ref/tags/$tag" --jq '.object | [.type, .sha] | @tsv')
	IFS=$'\t' read -r object_type object_sha <<< "$object"

	while [[ $object_type == tag ]]; do
		if (( depth++ >= 10 )); then
			echo "Tag $tag has too many nested tag objects." >&2
			return 1
		fi

		object=$(gh api "repos/$repository/git/tags/$object_sha" --jq '.object | [.type, .sha] | @tsv')
		IFS=$'\t' read -r object_type object_sha <<< "$object"
	done

	if [[ $object_type != commit ]]; then
		echo "Tag $tag does not resolve to a commit." >&2
		return 1
	fi

	echo "$object_sha"
}

if gh release view "$tag" --repo "$repository" >/dev/null 2>&1; then
	release=$(gh release view "$tag" --repo "$repository" --json assets,isDraft)
	draft=$(jq -r '.isDraft' <<< "$release")
	unexpected_assets=$(jq -r --arg asset_name "$asset_name" '[.assets[].name | select(. != $asset_name)] | join(", ")' <<< "$release")

	if [[ -n $unexpected_assets ]]; then
		echo "Draft release $tag contains unexpected assets: $unexpected_assets." >&2
		exit 1
	fi

	if [[ $draft == true ]]; then
		if ! gh api --method POST "repos/$repository/git/refs" -f ref="refs/tags/$tag" -f sha="$release_sha" >/dev/null 2>&1; then
			tag_sha=$(resolve_tag_commit)

			if [[ $tag_sha != "$release_sha" ]]; then
				gh api --method PATCH "repos/$repository/git/refs/tags/$tag" -f sha="$release_sha" -F force=true >/dev/null
			fi
		fi

		gh release edit "$tag" \
			--repo "$repository" \
			--title "$tag" \
			--notes-file "$notes_path"
		gh release upload "$tag" "$archive_path" --clobber --repo "$repository"
		exit
	fi

	tag_sha=$(resolve_tag_commit)

	if [[ $tag_sha != "$release_sha" ]]; then
		echo "Release $tag is already published from $tag_sha; expected $release_sha." >&2
		exit 1
	fi

	exit
fi

if ! gh api --method POST "repos/$repository/git/refs" -f ref="refs/tags/$tag" -f sha="$release_sha" >/dev/null 2>&1; then
	tag_sha=$(resolve_tag_commit)

	if [[ $tag_sha != "$release_sha" ]]; then
		echo "Tag $tag already targets $tag_sha; expected $release_sha." >&2
		exit 1
	fi
fi

gh release create "$tag" \
	"$archive_path" \
	--draft \
	--repo "$repository" \
	--title "$tag" \
	--verify-tag \
	--notes-file "$notes_path"
