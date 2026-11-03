# 5. Reviewing changes

The first four chapters explained how checkouts, gateways and the plugin work. This chapter turns that knowledge into a review practice. It's built from the findings of past code reviews on this repository: what was caught, what would have gone wrong, and the knowledge needed to catch it.

Use it in two ways:

- **Before you open a pull request**, walk through the questions for the areas your change touches.
- **When you review one**, use the case studies to recognize the patterns.

## Contents

- [Two mental models](#two-mental-models)
- [Reproduce, don't just read](#reproduce-dont-just-read)
- [Case studies](#case-studies)
- [Reviewer questions by area](#reviewer-questions-by-area)

## Two mental models

Almost every serious finding comes back to one of two ideas.

### A decision belongs to one attempt, and an attempt can span several requests

A payment attempt isn't one HTTP request. It can be a create-order AJAX call, a 3-D Secure challenge in the browser, and a final form post, with the shopper able to change things in between. So:

- every place where a decision is **reused** must be tied to evidence that proves it's the same attempt: a server-created reference, the exact card, the exact intent, the same cart;
- that evidence must be **invalidated** when the instrument, intent, order or session changes;
- a reuse record must store the **decision**, not just "this was verified", or a block can come back as an allow;
- the browser's **session ID** follows the same rule: one per attempt, reset after any step that ends the attempt.

### The plugin runs inside other people's code

The plugin is a guest in every request it touches:

- other plugins add checkout templates, AJAX senders, session handlers and payment flows;
- stores run old MySQL versions, persistent object caches, and custom WooCommerce setups;
- as a must-use plugin, the plugin loads even when WordPress's version checks would have stopped it;
- shoppers enter non-ASCII names, and requests carry whatever a script decided to send.

Code that's correct for the default WooCommerce store, with the default theme and a test gateway, is often wrong somewhere else.

## Reproduce, don't just read

The most serious findings in past reviews came from **running the flow against the real thing**, not from reading the diff:

- a PayPal replay that turned a block into an allow (#73), found in PayPal's sandbox;
- a card swapped on a Stripe setup intent after the verified step (#187), found with Stripe test mode and its client API;
- a stale setup intent carried in a later request (#189);
- a diagnostic reason lost when the SDK was blocked (#180);
- an extra submission when a checkout request used `global: false` or a `FormData` body (#183);
- a migration recorded as successful after a forced `ALTER` failure (#77).

Techniques that pay off:

- **Use real gateways in test mode.** Stripe test mode, WooPayments dev mode and the PayPal sandbox exercise intents, 3-D Secure and redirects that a dummy gateway skips.
- **Change things mid-attempt.** Swap the card after the first request, change the cart, switch from PayPal to an express button, click twice.
- **Break the dependencies.** Block `blackbox-api.wp.com` in the browser, disconnect Jetpack, return errors from `pre_http_request`, make `dbDelta` fail.
- **Delay the gateway.** Many gateways intercept the submission, do async work, and submit again seconds later. Code that assumes the form posts right away breaks there.
- **Use another checkout.** Try the classic checkout, the block checkout, a custom template, Pay for order, Add payment method, and express buttons on product and cart pages.

## Case studies

Each case shows a real finding, what would have gone wrong, and the knowledge that catches it.

### A stored "verified" flag replayed a block as an allow (#73)

- **The change:** The PayPal compatibility layer remembered that an attempt's session had been verified at PayPal's create-order request, so the final checkout could skip verification.
- **The problem:** It stored "verified" whatever the verdict was. A shopper blocked at create-order could complete the purchase through PayPal's other flow, which creates the PayPal order on the server without the create-order request, and skip the final verification.
- **What catches it:** Knowing that PayPal Payments has several completion paths, and the rule that a reuse record stores the *decision* and is bound to the PayPal order that was scored.

### A card swapped after the verified step (#187)

- **The change:** Stripe's setup-intent request was verified, and the final Add payment method form post reused the decision.
- **The problem:** Stripe's client API can attach a different card to the same setup intent before the form posts. A 3-D Secure card got an allow, the intent was switched to another card before authentication, and the second card was saved on the first card's allow.
- **What catches it:** Knowing that the setup intent is created and confirmed in an AJAX request before the form post, that the browser receives its client secret and can attach another card with it, and that a reused decision must be bound to the exact card.

### The same session ID scored two cards (#187)

- **The change:** After a blocked setup-intent request, Stripe left the form open with the session ID field still in it.
- **The problem:** The next submission skipped acquiring a new ID, and the request sent the already-verified one. Two cards were scored against one session, which is exactly what card testers do.
- **What catches it:** After any step that ends an attempt without completing it, remove the attempt's fields and reset. After a successful intermediate step, keep them for the same attempt's final post.

### The right resolver in the wrong request (#189)

- **The change:** The Stripe payment-data resolver read the setup intent posted with the request to find the card.
- **The problem:** The resolver runs in two requests: the setup-intent AJAX call and the final form post. In the AJAX call, a `wc-stripe-setup-intent` field from an *earlier* intent could be present, while Stripe processed the new card in `wc-stripe-payment-method`. The older card was scored, and the new one was saved on its allow.
- **What catches it:** Asking, for each field, which request makes it authoritative. The fix reads the setup intent only on the final form.

### A cleanup ran before the gateway submitted (#180)

- **The change:** Without a session ID, the Pay for order and Add payment method scripts removed their fields right after re-submitting the form.
- **The problem:** Stripe intercepts the submission, creates the payment method, and submits the form later. By then the reason field was gone, so the server recorded `unknown` instead of `sdk_unavailable`.
- **What catches it:** Never assume the form posts right after your handler. Test with a gateway that submits seconds later.

### A request the tracker couldn't see (#183)

- **The change:** The classic checkout script tracked the checkout request through jQuery's global `ajaxSend` and `ajaxComplete` events and read its body as a string.
- **The problem:** A request sent with `global: false` fires no global events, and some checkouts send `FormData` or `URLSearchParams` bodies. The result was an extra submission.
- **What catches it:** Knowing that third-party checkouts send `?wc-ajax=checkout` in different ways, and using per-request callbacks that handle every body type.

### A shared slot left empty (#179)

- **The change:** Express buttons took a prepared session ID from a single slot.
- **The problem:** If a shopper started PayPal (which took the prepared ID), cancelled, and then used Apple Pay, the slot was empty and later express attempts went out without a session.
- **What catches it:** Several payment flows on one page share one single-use slot. Switching flows must prepare a new ID.

### A non-actionable decision leaked out (#69)

- **The change:** A refactor removed the step that turned non-actionable decisions into `Allow`.
- **The problem:** `FraudDecision::Challenge` could reach the protectors, which can't enforce it.
- **What catches it:** A valid enum value isn't necessarily actionable. Only `FraudDecision::ACTIONABLE` may leave the decision handler.

### Fail-open allows that looked real (#74)

- **The change:** Recording attempts in the sessions log.
- **The problem:** Transport and parsing failures produce a synthetic `Allow`, which was recorded like a real one. During an outage, the merchant's log would fill with fake allows.
- **What catches it:** Failing open is about enforcement. Records and logs must still tell "the service said allow" from "we couldn't ask". The fix added the `verify_error` trigger.

### A rule that bypassed every override (#77)

- **The change:** Merchant rules decide before the automated decision filter and the automatic-protection setting.
- **The problem:** This was intended, but the docs still described the filter as the way to recover from any block, and extensions couldn't observe rule decisions at all.
- **What catches it:** Separating per-attempt automated verdicts from persistent merchant configuration. The fix added the `woocommerce_fraud_protection_rule_applied` action and renamed the filter to `woocommerce_fraud_protection_automated_decision`.

### An exemption tied to the wrong evidence (#185)

- **The change:** Skipping verification for cash payments in WooCommerce POS only when the order was created in the POS.
- **The problem:** A customer can order online and pay at the till. That payment has no shopper browser either, and a simulated block stopped the cashier.
- **What catches it:** Pay for order can complete an order created anywhere. The evidence (a validated cashier token) is about *who records the payment*, not where the order came from.

### A migration that failed but said it succeeded (#77)

- **The change:** A schema upgrade with `dbDelta()`.
- **The problem:** A failed `ALTER TABLE` was still stamped as the new schema version, so every later insert failed with "Unknown column". The error was also lost, because the next query clears `$wpdb->last_error`.
- **What catches it:** `dbDelta()` doesn't reliably report failures. Verify the resulting columns and indexes before stamping the version, and capture errors immediately (or from `$EZSQL_ERROR`).

### A store-wide fatal from a container call (#62)

- **The change:** Resolving the controller from `wc_get_container()`.
- **The problem:** Before WooCommerce 9.5, the container only resolved registered classes. On an older WooCommerce, the whole store would fatal, and as a must-use plugin, the `WC requires at least` header wouldn't stop it from loading.
- **What catches it:** Knowing when WooCommerce APIs appeared, and that must-use plugins bypass header checks. The fix raised the minimum and added a runtime version guard.

## Reviewer questions by area

### Gateway flows

- List every server request in one attempt: gateway AJAX or REST calls, the Store API request, the final form post, webhooks. Which one does the plugin verify? Which one sends the card to the network? Can a later request reuse the decision?
- Can the instrument change between the verified request and the one that charges or saves it? Is the reused decision bound to the exact card, intent or order?
- Does the gateway intercept the submission and submit again later? Are your fields still there when it does?
- Does the gateway have other entry points (redirect flows, older endpoints, express buttons on product, cart and Pay for order pages) that skip your hook?
- Which script handles really mean "express buttons rendered", as opposed to "block integration registered"?
- Does an exemption depend on the right evidence?

### Session ID lifecycle

- Is each session ID used for exactly one attempt?
- After a failed or blocked step, are the fields cleared and the session reset? After a successful intermediate step, is the ID kept for the final post?
- Is single-use bookkeeping on the lowest-level path every caller uses (`acquireSessionIdResult()`)?
- What happens when the shopper switches between flows that share the prepared slot?
- Does request tracking handle `global: false`, `FormData` and `URLSearchParams`, and handlers that let the submission through before yours?
- Can a background `reset()` interrupt something in flight?
- Can a second click start a parallel attempt?
- Do the reasons tell "our script didn't run" from "our script ran and had nothing"?

### Decisions and fail open

- Can anything outside `FraudDecision::ACTIONABLE` leave the decision handler?
- When a filter returns bad data or throws, does the fallback keep the decision that entered it?
- Is a synthetic allow distinguishable from a real one in records and logs?
- Does a reuse record store the decision, bound to evidence, used once?
- Does an error after a block exists keep the block?
- Do merchant rules keep precedence, and can extensions observe the outcome?

### Evidence

- What keys the reuse record: session ID, user, order, card, intent, cart? Is it bounded?
- Is each fact (IP, email, card) derived once and used everywhere, in the same normalized form in PHP and SQL?
- Could a value from an earlier attempt, or a value the browser sends, be used to skip verification?

### Checkout flow and hook timing

- Which entry points perform this action? Add to cart has three (`WC_Form_Handler` on `wp_loaded`, `WC_AJAX`, the Store API). Checkout has two. Payment has Pay for order and Add payment method too.
- Does the hook also fire for programmatic calls by other plugins?
- Does the event mean what its name says? "Order placed" is not "order paid". Does it fire in the shopper's request, or in a webhook or return request?
- Does verification run before or after the event you track?
- Do custom checkout templates fire the hook you rely on?
- Do `is_checkout()` and friends include the Pay for order and order received endpoints? (They do.)
- Does `accepted_args` match the callback?

### WooCommerce and WordPress internals

- Does the code build WooCommerce internals directly (`new WC_Session_Handler()`) instead of using `WC()->initialize_session()` or `wc_load_cart()`?
- Can `WC()`, `WC()->session` or `WC()->cart` be null here?
- Is every optional class (Jetpack, a gateway's classes) checked with `class_exists()`?
- Does it work on the oldest supported WooCommerce?
- Must this file stay parseable on old PHP for the kill switch?
- Can a static facade be reached before the container has called `init()`?
- Does `WC()->session->set()` run only when the value changes? Each call marks the session dirty and causes a database write.

### Database and caching

- Do indexed text columns fit 191 characters for utf8mb4?
- Does truncation use `mb_substr()`?
- Does the migration verify the schema before stamping the version, and capture the real error?
- Does a rollback to an older version cause migrations to run again?
- Can an object-cache refill race a write? Is there a TTL?
- Do concurrent duplicates raise the same exception on create and update?
- Do new tables with emails or IP addresses need privacy erasers and exporters?

### Public API

- Is this something the plugin fires (a hook) or something callers invoke (a method)? Don't disguise a method as an action.
- Does a new public hook have a real consumer? Released hooks are permanent.
- Can caller-supplied arrays override trusted keys through `array_merge()` order?
- Are contract values constants or enums, typed until serialization?
- Do the [API reference](../api-reference.md), `@since` tags and examples match the code?
- Would a compatibility layer, moved into its gateway, need any internal class?
- Are unstable service values documented as informational?

### Logging and privacy

- Does every early return on an integration path leave a log entry?
- Are user and request values in structured context, sanitized, and out of the message?
- Is the `source` context key left alone? The WooCommerce logger uses it to choose the log file; use `event_source`.
- Are forwarded keys on the `LogContextSanitizer` allowlist, with a privacy review?
- Does the message reveal anything about how the service decides?

### Tests

- Does the test go through the real hook or flow, or only call the callback, leaving the hook registration untested?
- Would it fail if the invariant broke where it's actually enforced?
- Are all contract values and failure branches covered?
- Are public literals, such as `config.sessionIdField`, pinned?
- Are options and hooks restored? Use `pre_option_*` filters to simulate a missing option.
- Do test names match their assertions?

### Admin UI

- Is tooltip information also reachable by touch, keyboard and screen reader?
- Is there one source of truth for settings, with writes going through store actions?
- Is navigation state in the URL (the WooCommerce admin `path` parameter), not in `localStorage`?
- Are style overrides scoped to the plugin, RTL-safe, and free of internal class names?
- Does navigation happen only after a save succeeds? Can Escape close a dialog mid-save?

### Scope

- Is this the smallest change that does the job?
- Does it add compatibility code for a third-party extension that someone will have to keep up to date?
- Does it rely on a contract the dependency already provides, rather than adding duplicate timers or fallbacks?
