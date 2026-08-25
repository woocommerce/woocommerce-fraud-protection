#!/usr/bin/env bash

set -euo pipefail

version=${1:?Provide the release version.}
release_sha=${2:?Provide the tested release commit.}
archive_path=${3:?Provide the release archive.}
release_pr_url=${4:?Provide the release pull request URL.}
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

if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
	echo "Release: $release_url" >> "$GITHUB_STEP_SUMMARY"
	echo "Merge pull request $release_pr_url with a merge commit." >> "$GITHUB_STEP_SUMMARY"
fi

echo "$release_url"
