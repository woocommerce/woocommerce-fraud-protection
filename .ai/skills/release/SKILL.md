---
name: release
description: Prepare and publish a GitHub Release for the WooCommerce Fraud Protection plugin. Use when creating a new release.
user_invocable: true
arguments:
  - name: version
    description: "Version to release, such as 0.1.9. If omitted, propose the version in the placeholder changelog block."
    required: false
---

# Release

Prepare a release pull request and use the protected GitHub Actions workflow to build, test, and publish the release.

## Instructions

1. Read the version from the `YYYY-xx-xx` block at the top of `changelog.txt`, the current package version, and the latest `v*` tag. Use the requested version when supplied. Otherwise, propose the placeholder version. Stop if the tag already exists or the requested version does not match the placeholder.

2. Review commits and pull requests since the latest release. Confirm that every merchant-facing or developer-facing change has an accurate entry in the placeholder block. Do not add entries for tests, CI, documentation, internal refactoring, or a defect introduced and fixed within this release cycle.

3. Apply the public changelog framing rules to the complete placeholder block. Show the reviewed block to the user and wait for explicit approval before changing release files.

4. Select the branch that must receive the release. Use `trunk` for the current release line or its maintenance branch for an older patch line. Create `release/<version>` from that branch.

5. Replace `YYYY-xx-xx` with today's date in `YYYY-MM-DD` form. Do not add the next placeholder to the release package; the first later product pull request creates it.

6. Update the plugin header and `WC_FRAUD_PROTECTION_VERSION` in `src/Internal/FraudProtectionPlugin/PluginInitializer.php`. Run the following command so `package.json` and `package-lock.json` remain synchronized:

   ```bash
   nvm use && npm version <version> --no-git-tag-version
   ```

7. Run `bin/validate-release.sh <version>` and review the release diff. Commit only `woocommerce-fraud-protection.php`, `src/Internal/FraudProtectionPlugin/PluginInitializer.php`, `package.json`, `package-lock.json`, and `changelog.txt` with title `Fraud Protection: Bump version to <version>`.

8. Push the branch and open a pull request against the selected target branch. Explain why the release is needed, include the approved changelog block, and provide manual installation and checkout tests for a generic test site. Include this notice near the top of the pull request description:

   ```markdown
   > [!IMPORTANT]
   > Keep this pull request open until the GitHub release is published. After publication, merge it with a merge commit. Do not squash or rebase it.
   ```

   Wait for CI to pass, approval, and confirmation that the pull request is ready to merge. Do not merge it yet.

9. Start the `Release` workflow on the release branch with the approved version:

   ```bash
   gh workflow run release.yml --ref release/<version> -f version=<version> -f target_branch=<target-branch>
   ```

10. The workflow validates the branch, target branch, version, changelog, pull request state, and required checks. It builds the release ZIP in CI and derives a QIT ZIP that adds only the dependency manifests needed for audits. QIT runs its activation and security tests against the QIT ZIP. The workflow then waits at the `release` environment before publication. Ask the user to download the release ZIP artifact and complete smoke tests on representative WoA test sites. Wait for confirmation that the smoke tests passed, then ask the user to approve the environment job. Do not create the tag or GitHub release locally.

11. After the workflow completes, verify that the release is public, its tag targets the tested release commit, and `woocommerce-fraud-protection.zip` is attached. Ask the user to merge the release pull request with a merge commit. Do not use squash or rebase. Verify that the tag commit is an ancestor of the target branch, then report the release and pull request URLs.

## Requirements

- Product pull requests own their changelog entries. The release pass checks wording and coverage; it does not draft the release from scratch.
- The release pull request replaces the placeholder date, so the published changelog starts with the released version.
- The release pull request must use `release/<version>`. It must be approved and ready to merge before the workflow runs, then merged with a merge commit after publication.
- The target branch must use a GitHub ruleset that defines the required status checks. The release workflow stops if the target branch has no required checks or any required check has not passed.
- `vendor/` must remain in the ZIP because the plugin bootstrap requires its autoloader.
- Configure the GitHub `release` environment with required reviewers before using this workflow.
