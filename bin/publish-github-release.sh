#!/usr/bin/env bash

set -euo pipefail

version=${1:?Provide the release version.}
release_sha=${2:?Provide the tested release commit.}
archive_path=${3:?Provide the release archive.}
release_pr_url=${4:?Provide the release pull request URL.}
repository=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required.}
tag="v$version"

release=$(gh release view "$tag" --repo "$repository" --json assets,isDraft,targetCommitish,url)
draft=$(jq -r '.isDraft' <<< "$release")
target=$(jq -r '.targetCommitish' <<< "$release")
remote_digest=$(jq -r '.assets[] | select(.name == "woocommerce-fraud-protection.zip") | .digest' <<< "$release")
local_digest="sha256:$(sha256sum "$archive_path" | cut -d ' ' -f 1)"

if [[ $target != "$release_sha" ]]; then
	echo "The GitHub release does not target the tested commit." >&2
	exit 1
fi

if [[ $local_digest != "$remote_digest" ]]; then
	echo "Uploaded release asset digest does not match the built ZIP." >&2
	exit 1
fi

if [[ $draft == true ]]; then
	gh release edit "$tag" --repo "$repository" --draft=false
fi

release_url=$(gh release view "$tag" --repo "$repository" --json url --jq .url)

if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
	echo "Release: $release_url" >> "$GITHUB_STEP_SUMMARY"
	echo "Merge pull request $release_pr_url with a merge commit." >> "$GITHUB_STEP_SUMMARY"
fi

echo "$release_url"
