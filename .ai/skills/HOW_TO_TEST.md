I've switched the plugin to `flush()` in this branch. When `flush()` reports an active challenge, the payment now waits for it to end and then continues. The JS tests (333) and `lint:js` pass, and `phpcs` is clean on the two PHP files I touched. I haven't run it in a browser yet; the steps below do that.

## What changed (left in the working tree, not committed)

- **`assets/js/blackbox-init.js`**: `acquireSessionIdResult()` now uses `flush()` when the SDK has it, and falls back to the old `getSessionId()` path when it doesn't.
  - `flush()` gets the existing 3000 ms timeout as its deadline. A 500 ms safety timer only matters if the SDK never answers.
  - If the SDK returns an ID, that ID is sent even when its call timed out or failed. Only an attempt with no ID gets the `timeout` or `sdk_error` reason.
  - **During a challenge**, the payment waits until the SDK's state leaves `challenge` (solved, failed or reset), with a 5-minute cap. It then calls `flush()` once more to get the current ID and doesn't wait for a second challenge.
  - **Express checkout** still prepares its ID with `getSessionId()`. Calling `flush()` ahead of time sends nothing new and could show a challenge before the shopper starts paying.
- **`tests/js/blackbox-init.test.js`**: 20 new tests for the flush path. The existing tests keep covering the fallback.
- **Comments only** in `BlackboxScriptHandler.php` and `BlocksCheckoutProtector.php`.
- **`changelog.txt`**: one "Updated" entry.
- I ran `npm ci` and `composer install` in this worktree, which had no `node_modules` or `vendor` yet.

## How to simulate a challenge

1. **Start the harness** in its own terminal with `cd ~/fake-blackbox && bin/serve.sh`. It serves http://localhost:8890, with the 2.0.0 bundles and fake SDK from the previous step.

2. **Mount the harness in this worktree's store.**
   - Create `~/wfp-worktrees/adjust-to-blackbox-sdk-2/.wp-env.override.json`:
     ```json
     {
     	"mappings": {
     		"wp-content/mu-plugins/fake-blackbox": "/home/konamiman/fake-blackbox",
     		"wp-content/mu-plugins/fake-blackbox-loader.php": "/home/konamiman/fake-blackbox/mu-plugin/loader.php"
     	}
     }
     ```
     Don't use the README's `{ "port": 8890 }` example, because the harness uses that port. Port 8888 is free right now.
   - Run `npm run env:start`.
   - Confirm the mount with `npm run env -- run cli wp plugin list --status=must-use`. `fake-blackbox-loader` should be listed.

3. **Configure the harness** at http://localhost:8890/admin/settings.
   - Set Mode to `fake-sdk` and save. Leave the thresholds at their defaults.
   - Under **Queue a scenario for the next new session**, set Label to `page-load challenge` and Challenge at collect to `force`, then click **Queue**.
   - Each page load starts a new session and takes the first queued scenario. So queue it just before you open the checkout, and don't load other checkout pages in between.

4. **Place an order.**
   - In the store (http://localhost:8888), add a product to the cart and open the checkout. The classic and block checkouts should behave the same way.
   - Fill in the details and pick an offline method such as Cash on delivery.
   - Click **Place order**.

5. **What you should see:**
   - The checkbox challenge appears as an overlay, and the order doesn't submit while it's open (the button stays busy).
   - Tick the box. After the short success display, the order submits on its own and the order-received page loads.
   - Nothing should happen before you click: the challenge kept from page load only starts when you place the order.

6. **Check the timeline** in `/admin/sessions`, then open the session. You should see, in order:
   - `Directive received on the page-load collect and kept`
   - `flush() starts the kept page-load directive`
   - `State changed to "challenge"`
   - `flush() resolved: outcome "ok", … challengePending true`
   - `Proof accepted`
   - `State changed to "ready"`
   - the second `flush() resolved … challengePending false`
   - a verify with decision `allow`

### Variations worth trying

- **Failed challenge:** queue `force` with **Reject the first N proofs** set to `3`. With the default `max_escalations` of 2, every attempt fails. Once the failure display ends, the order should submit, and verify should block it because the challenge was never solved. The shopper sees the generic message.
- **Slow service, no challenge:** set **Collect delay** to `5000` and keep the default scope. After about 3 seconds the order goes through with the existing ID and no empty-ID reason. The timeline shows `outcome "timeout", session ID present`.
- **No session at all:** set the scope to `Every collect` with HTTP 500. The order should carry an empty ID with the reason `sdk_error`.
- **Other payment surfaces:** Pay for order and Add payment method use the same function, so the same steps apply there.

Use `fake-sdk` mode for these. In `real-sdk` mode, the harness counts `flush()`'s own collect as the challenge solution, so a challenge would "pass" without anyone ticking the box.