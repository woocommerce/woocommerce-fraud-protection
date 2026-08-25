#!/usr/bin/env bash

set -euo pipefail

version=${1:?Provide the release version.}
release_sha=${2:?Provide the tested release commit.}
archive_path=${3:?Provide the release archive.}
notes_path=${4:?Provide the release notes.}
repository=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required.}
tag="v$version"

if gh release view "$tag" --repo "$repository" >/dev/null 2>&1; then
	draft=$(gh release view "$tag" --repo "$repository" --json isDraft --jq .isDraft)
	target=$(gh release view "$tag" --repo "$repository" --json targetCommitish --jq .targetCommitish)

	if [[ $draft == true ]]; then
		gh release edit "$tag" \
			--repo "$repository" \
			--target "$release_sha" \
			--title "$tag" \
			--notes-file "$notes_path"
		gh release upload "$tag" "$archive_path" --clobber --repo "$repository"
		exit
	fi

	if [[ $target != "$release_sha" ]]; then
		echo "Release $tag is already published for $target; expected $release_sha." >&2
		exit 1
	fi

	exit
fi

gh release create "$tag" \
	"$archive_path" \
	--draft \
	--repo "$repository" \
	--target "$release_sha" \
	--title "$tag" \
	--notes-file "$notes_path"
