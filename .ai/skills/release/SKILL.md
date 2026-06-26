---
name: release
description: Build and publish a GitHub Release for the WooCommerce Fraud Protection plugin. Use when creating a new release.
user_invocable: true
arguments:
  - name: version
    description: "Version to release (e.g., 0.1.3). If omitted, propose the next version from the latest git tag and ask the user to confirm."
    required: false
---

# Release

Bump the version, build the plugin zip, ship a release PR, and create a GitHub Release once it merges.

## Inputs

- `version` (optional): target version like `0.1.3`. If omitted, propose the next patch bump from the latest `v*` git tag and confirm with the user before proceeding.

## Instructions

1. **Pick the target version**
   - Read the current version from `package.json` and the latest `v*` git tag (`git tag --list 'v*' | sort -V | tail -1`).
   - If the user supplied `{{version}}`, use it. Otherwise propose the next patch bump and confirm.
   - Stop and ask if the proposed version is already tagged in git.

2. **Gather commits since the last release**
   - Run `git log v<last>..HEAD --no-merges --oneline` on `trunk` to see what landed.
   - Fetch the corresponding PR descriptions (`gh pr view <num> --json title,body`) to understand intent.

3. **Bump version in three locations** (all must match the target version):
   - `woocommerce-fraud-protection.php` plugin header (`Version:`)
   - `src/PluginInitializer.php` constant (`WC_FRAUD_PROTECTION_VERSION`, defined in `run()`)
   - `package.json` (`version`)

4. **Apply changelog framing rules** to every candidate bullet. This is a public release; bullets must be appropriate for a public audience. Consult with the user before continuing.

5. **Draft the `changelog.txt` entry** following step 4's rules and show it to the user for review before continuing.
   - Format: `YYYY-MM-DD - version <version>` heading followed by `* Added|Updated|Fixed - ...` bullets, matching prior entries.
   - Use today's date in `YYYY-MM-DD` form, not a planned rollout date.
   - Pause for explicit user approval of the wording before writing any files.

6. **Run checks locally** (parallel where possible):
   ```
   npm run lint -- --ignore='*/sdd/*'   # phpcs picks up the untracked sdd/ scratch dir locally
   npm run test:js                       # skip npm run test:php; PHP unit tests run on CI
   npm run phpstan
   ```
   - PHP unit tests are intentionally deferred to CI - do not bootstrap the WP PHPUnit env locally.
   - If lint reports failures only inside `sdd/`, those are local scratch files (gitignored). Confirm product code is clean by re-running with `--ignore='*/sdd/*'`.
   - If any check fails on product code, stop and fix before releasing.

7. **Build the zip**:
   ```
   npm run build:release
   ```
   Confirm `woocommerce-fraud-protection.zip` was created. Detailed content verification happens in step 8.

8. **Verify the zip's contents** before shipping.
   - List the archive and confirm all entries are under a top-level `woocommerce-fraud-protection/` directory. Expected files:
     - `woocommerce-fraud-protection-loader.php`
     - `woocommerce-fraud-protection.php`
     - `changelog.txt`
     - `assets/js/*.js`
     - `src/**/*.php`
     - `vendor/autoload.php` and `vendor/composer/*` (PSR-4 autoloader; required at runtime - bootstrap requires it)
   - Confirm the plugin file and initializer inside the zip have the new version:
     ```
     unzip -p woocommerce-fraud-protection.zip woocommerce-fraud-protection/woocommerce-fraud-protection.php | grep "Version:"
     unzip -p woocommerce-fraud-protection.zip woocommerce-fraud-protection/src/PluginInitializer.php | grep "WC_FRAUD_PROTECTION_VERSION"
     ```
   - Confirm the changelog inside the zip has the new entry at the top:
     ```
     unzip -p woocommerce-fraud-protection.zip woocommerce-fraud-protection/changelog.txt | head -3
     ```
   - Flag anything unexpected (extra files, tests bundled, `node_modules/`, missing `vendor/`). Stop and ask the user before continuing if anything is off.

9. **Open a release PR** (do NOT push the bump commit directly to `trunk`):
   - Create branch `release/<version>` from `trunk`.
   - Commit the bump with title `Fraud Protection: Bump version to <version>` (stage only `woocommerce-fraud-protection.php`, `src/PluginInitializer.php`, `package.json`, `changelog.txt` - never `sdd/`).
   - `git push -u origin release/<version>`.
   - Open PR with `gh pr create --base trunk --head release/<version>` including the changelog bullets in the body and a short test plan.
   - Hand the PR URL to the user and wait for them to merge it (CI must be green first).

10. **After merge, pull trunk and rebuild**:
    ```
    git switch trunk && git pull --ff-only origin trunk
    npm run build:release
    ```
    Re-run step 8 on the rebuilt zip (the merged commit may differ from the PR-branch commit).

11. **Create the GitHub Release**:
   ```
   gh release create "v<version>" \
     woocommerce-fraud-protection.zip \
     --title "v<version>" \
     --notes "<changelog bullets for this version, verbatim from changelog.txt>"
   ```
   Verify with `gh release view v<version>` that:
   - `isDraft: false`, `isPrerelease: false`
   - The `woocommerce-fraud-protection.zip` asset uploaded successfully

12. **Report** - share the release URL with the user. Offer to clean up the local `release/<version>` branch (`git branch -d release/<version>`) since the remote branch is deleted on merge.

## Gotchas

- **Never push the version-bump commit directly to `trunk`.** Always go through a `release/<version>` PR so CI runs against the release commit.
- **The `sdd/` directory is local-only** and gitignored. Never stage it; ignore it during phpcs.
- **`vendor/` belongs in the zip.** The bootstrap requires `vendor/autoload.php`. If the build excludes it, the plugin will fatal on load.
- **Changelog dates use today's date** in `YYYY-MM-DD` form, not a future or rollout-planned date.
