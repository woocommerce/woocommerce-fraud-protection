---
name: woocommerce-git
description: Guidelines for git and GitHub operations in the WooCommerce Fraud Protection repository.
---

# Git Guidelines

## Branches

Branch from `trunk` (the main branch) unless the work targets an older maintenance line. Release branches are named `release/<version>` and are created by the `release` skill. Feature branches commonly carry the Linear issue key, e.g. `woofp-177-create-merchant-rules`.

## Pull Requests

- Prefix the title with `Fraud Protection:` followed by an imperative summary, e.g. `Fraud Protection: Add merchant rule creation`.
- Follow the template at `.github/pull_request_template.md` (lowercase file name). Its sections are **Changes proposed**, **How to test**, and **Changelog**. Keep all three; write `N/A` under "How to test" when no manual test applies.
- Before drafting, inspect two or three recent merged pull requests (`gh pr list --state merged --limit 5`, then `gh pr view <number>`) and follow their current structure.
- "Changes proposed" explains why the change is needed and how it solves the issue.
- "How to test" lists numbered manual steps a reviewer can run on a generic WooCommerce test site. Do not add steps that only repeat automated checks.
- Tick the changelog checklist item. Merchant-facing and developer-facing changes need an entry under the placeholder release in `changelog.txt`; tests, CI, documentation, and internal refactoring do not (see "Issues and pull requests" in `AGENTS.md`).
- End the description with `Closes WOOFP-<n>` when the pull request resolves a Linear issue.
- Do not disclose private service behavior in the title, description, or commit messages.
- Pass the body via a HEREDOC to `gh pr create --body`.

## Commits

Write imperative commit subjects that describe the change (`Connect checkout attempts to rule management`). Pull requests are squash-merged, so the pull request title becomes the commit on `trunk`; release pull requests are the exception and are merged with a merge commit after the release is published (see the `release` skill).
