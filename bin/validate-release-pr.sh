#!/usr/bin/env bash

set -euo pipefail

release_branch=${1:?Provide the release branch.}
release_sha=${2:?Provide the tested release commit.}
target_branch=${3:?Provide the pull request target branch.}
repository=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required.}

pull_requests=$(gh pr list \
	--repo "$repository" \
	--head "$release_branch" \
	--state open \
	--json baseRefName,headRefOid,isDraft,mergeStateStatus,url)

if [[ $(jq length <<< "$pull_requests") -ne 1 ]]; then
	echo "Expected one open pull request from $release_branch." >&2
	exit 1
fi

pull_request=$(jq '.[0]' <<< "$pull_requests")
head_sha=$(jq -r '.headRefOid' <<< "$pull_request")
is_draft=$(jq -r '.isDraft' <<< "$pull_request")
merge_state=$(jq -r '.mergeStateStatus' <<< "$pull_request")
base_branch=$(jq -r '.baseRefName' <<< "$pull_request")
pull_request_url=$(jq -r '.url' <<< "$pull_request")

if [[ $base_branch != "$target_branch" ]]; then
	echo "Release pull request target mismatch: expected $target_branch, found $base_branch." >&2
	exit 1
fi

if [[ $head_sha != "$release_sha" ]]; then
	echo "The tested commit is no longer the pull request head." >&2
	exit 1
fi

if [[ $is_draft != false || $merge_state != CLEAN ]]; then
	echo "The release pull request must be ready to merge." >&2
	exit 1
fi

checks=$(gh pr checks "$pull_request_url" --repo "$repository" --required --json bucket || true)

if ! jq -e 'type == "array"' <<< "${checks:-null}" >/dev/null || [[ $(jq length <<< "$checks") -eq 0 ]]; then
	echo "The pull request target branch has no required status checks." >&2
	exit 1
fi

if jq -e 'any(.bucket != "pass" and .bucket != "skipping")' <<< "$checks" >/dev/null; then
	echo "The release pull request has required status checks that have not passed." >&2
	exit 1
fi

echo "$pull_request_url"
