#!/usr/bin/env bash

set -euo pipefail

version=${1:?Provide the release version.}
release_sha=${2:?Provide the tested release commit.}
archive_path=${3:?Provide the release archive.}
notes_path=${4:?Provide the release notes.}
release_pr_url=${5:?Provide the release pull request URL.}
repository=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required.}
tag="v$version"
asset_name=woocommerce-fraud-protection.zip
translation_import_url="https://translate.wordpress.com/api/import-new-release/woocommerce/woocommerce-fraud-protection/$tag"

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

ensure_release_tag() {
	local update_existing=${1:?Specify whether an existing tag can be updated.}
	local tag_sha

	if gh api --method POST "repos/$repository/git/refs" -f ref="refs/tags/$tag" -f sha="$release_sha" >/dev/null 2>&1; then
		return
	fi

	tag_sha=$(resolve_tag_commit)

	if [[ $tag_sha == "$release_sha" ]]; then
		return
	fi

	if [[ $update_existing == true ]]; then
		gh api --method PATCH "repos/$repository/git/refs/tags/$tag" -f sha="$release_sha" -F force=true >/dev/null
		return
	fi

	echo "Tag $tag already targets $tag_sha; expected $release_sha." >&2
	return 1
}

if release=$(gh release view "$tag" --repo "$repository" --json assets,isDraft 2>/dev/null); then
	draft=$(jq -r '.isDraft' <<< "$release")

	if [[ $draft == true ]]; then
		unexpected_assets=$(jq -r --arg asset_name "$asset_name" '[.assets[].name | select(. != $asset_name)] | join(", ")' <<< "$release")

		if [[ -n $unexpected_assets ]]; then
			echo "Draft release $tag contains unexpected assets: $unexpected_assets." >&2
			exit 1
		fi

		ensure_release_tag true
		gh release edit "$tag" \
			--repo "$repository" \
			--title "$tag" \
			--notes-file "$notes_path"
		gh release upload "$tag" "$archive_path" --clobber --repo "$repository"
	fi
else
	ensure_release_tag false
	gh release create "$tag" \
		"$archive_path" \
		--draft \
		--repo "$repository" \
		--title "$tag" \
		--verify-tag \
		--notes-file "$notes_path"
fi

release=$(gh release view "$tag" --repo "$repository" --json assets,isDraft,url)
draft=$(jq -r '.isDraft' <<< "$release")
release_url=$(jq -r '.url' <<< "$release")
tag_sha=$(resolve_tag_commit)
asset_count=$(jq '.assets | length' <<< "$release")
remote_digest=$(jq -r --arg asset_name "$asset_name" '.assets[] | select(.name == $asset_name) | .digest' <<< "$release")
local_digest="sha256:$(sha256sum "$archive_path" | cut -d ' ' -f 1)"

if [[ $tag_sha != "$release_sha" ]]; then
	echo "Tag $tag targets $tag_sha; expected the tested commit $release_sha." >&2
	exit 1
fi

if [[ $asset_count -ne 1 || -z $remote_digest ]]; then
	echo "The GitHub release must contain only $asset_name." >&2
	exit 1
fi

if [[ $local_digest != "$remote_digest" ]]; then
	echo "Uploaded release asset digest does not match the built ZIP." >&2
	exit 1
fi

if [[ $draft == true ]]; then
	gh release edit "$tag" --repo "$repository" --draft=false
fi

translation_import_queued=false
translation_import_response=''
if translation_import_response=$(curl \
	--connect-timeout 10 \
	--fail-with-body \
	--location \
	--max-time 30 \
	--request POST \
	--silent \
	--show-error \
	"$translation_import_url"); then
	if jq -e '.success == true' <<< "$translation_import_response" >/dev/null; then
		translation_import_queued=true
	fi
fi

if [[ -n $translation_import_response ]]; then
	printf '%s\n' "$translation_import_response" | sed 's/^/Translation service response: /'
else
	echo "Translation service returned an empty response."
fi

if [[ $translation_import_queued == true ]]; then
	echo "Queued the translation import for the published release $tag."
else
	echo "The GitHub release is public, but its translation import was not confirmed." >&2
	if [[ ${GITHUB_ACTIONS:-} == true ]]; then
		echo "::warning title=Translation import not confirmed::The GitHub release is public. Check GlotPress for the latest plugin version before retrying the POST request."
	fi
fi

if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
	echo "Release: $release_url" >> "$GITHUB_STEP_SUMMARY"
	echo "Merge pull request $release_pr_url with a merge commit." >> "$GITHUB_STEP_SUMMARY"
fi

echo "$release_url"
