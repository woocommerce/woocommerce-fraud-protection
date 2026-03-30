---
name: release
description: Build and publish a GitHub Release for the WooCommerce Fraud Protection plugin. Use when creating a new release.
user_invocable: true
arguments:
  - name: version
    description: "Version to release (e.g., 0.1.0). Must match the version in the plugin header."
    required: true
---

# Release

Build the plugin zip and create a GitHub Release.

## Instructions

1. **Validate version** — confirm the `version` argument matches all three locations:
   - `woocommerce-fraud-protection.php` plugin header (`Version:`)
   - `woocommerce-fraud-protection.php` constant (`WC_FRAUD_PROTECTION_VERSION`)
   - `package.json` (`version`)

   If they don't match, stop and tell the user.

2. **Run checks** — run these in parallel:
   ```
   npm run lint
   npm run test
   npm run phpstan
   ```
   If any fail, stop and fix before releasing.

3. **Build the zip**:
   ```
   npm run build:release
   ```
   Verify `woocommerce-fraud-protection.zip` was created. List its contents and confirm it only includes the expected files: `woocommerce-fraud-protection-loader.php`, `woocommerce-fraud-protection.php`, `changelog.txt`, `assets/js/*.js`, and `src/**/*.php`. All files should be inside a `woocommerce-fraud-protection` directory. Flag anything unexpected.

4. **Extract release notes** from `changelog.txt` — find the section for `{{version}}` and extract the bullet points. Format them as the release body.

5. **Create the GitHub Release**:
   ```
   gh release create "v{{version}}" \
     woocommerce-fraud-protection.zip \
     --title "{{version}}" \
     --notes "{{notes}}"
   ```

6. **Report** — show the release URL to the user.
