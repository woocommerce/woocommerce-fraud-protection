# Working with Hooks

## Table of Contents

- [Adding Hooks](#adding-hooks)
- [Hook Callback Conventions](#hook-callback-conventions)
- [Hook Docblocks](#hook-docblocks)
- [Validating Filtered Values](#validating-filtered-values)

## Adding Hooks

Add public hooks only when an extension contract is required. Released hooks must be maintained (see "Hooks, logging, and errors" in `AGENTS.md`). The released extension filters are `woocommerce_fraud_protection_resolved_payment_data` and `woocommerce_fraud_protection_skip_session_verify`; their contracts are documented in the "Public API" section of `README.md`.

Prefix plugin hook names with `woocommerce_fraud_protection_`.

## Hook Callback Conventions

- Callbacks are registered from the component's `register()` method (see [dependency-injection.md](dependency-injection.md)), never from the constructor.
- Callbacks are public methods marked `@internal` so they are not treated as public API.
- Name a callback after what it does (`verify_and_block`, `enqueue_pay_for_order_script`, `add_settings_page`). The Core `handle_{hook_name}` form is used only for lifecycle callbacks such as `handle_init` and `handle_woocommerce_loaded`.
- Follow the timing and priority of the nearest existing component. Hook timing and priorities are intentional.
- Treat every value a hook passes in as mixed input, even when the documented type is specific. Validate the type before using it, and on invalid input preserve the original safe value or skip only the invalid field.

**Example:**

```php
/**
 * Register hooks for pay-for-order fraud protection.
 *
 * @return void
 */
public function register(): void {
    add_action( 'woocommerce_before_pay_action', array( $this, 'verify_and_block' ) );
}

/**
 * Verify the session and block the payment on a Block decision.
 *
 * @internal
 *
 * @param \WC_Order $order The order being paid for.
 * @return void
 */
public function verify_and_block( \WC_Order $order ): void {
    // ...
}
```

## Hook Docblocks

All hooks fired by the plugin must have a docblock with:

- A description of when the hook fires
- `@since` with the plugin version (see "Version Information" in [SKILL.md](SKILL.md))
- `@param` tags for each parameter passed to the hook

Existing hook docblocks place the `@since` lines before the `@param` list. Keep that placement when editing a hook. When a released hook's contract changes, add a new `@since <version> <what changed>` line under the original instead of editing it.

If you modify a line that fires a hook without a docblock, add one. Use `git log -S "hook_name"` and `changelog.txt` to find the version that introduced it.

**Action hook example:**

```php
/**
 * Fires when a merchant rule has decided the session outcome.
 *
 * @since 0.1.6
 *
 * @param int                  $rule_id           The id of the rule that decided the session.
 * @param FraudDecision        $applied_decision  The enforced decision (the rule's action).
 * @param FraudDecision        $received_decision The automated decision that the rule superseded.
 * @param array<string, mixed> $session_data      The session data that was analyzed.
 */
do_action( 'woocommerce_fraud_protection_rule_applied', $rule_id, $applied_decision, $received_decision, $session_data );
```

**Filter hook example with a contract change:**

```php
/**
 * Filters the automated fraud protection decision before it is applied.
 *
 * @since 0.1.0
 * @since 0.1.6 Renamed from `woocommerce_fraud_protection_decision`.
 *
 * @param FraudDecision        $decision     The decision from the API (Allow or Block).
 * @param array<string, mixed> $session_data The session data that was analyzed.
 */
$filtered = apply_filters( 'woocommerce_fraud_protection_automated_decision', $decision, $session_data );
```

## Validating Filtered Values

Validate every filtered value. On invalid data, return to the original safe value. When a throwing callback must not break the flow, wrap the `apply_filters()` call in `try`/`catch ( \Throwable )`, log the failure with `FraudProtectionController::log()`, and continue with the value that entered the filter. See "Fail open" in `AGENTS.md` for the decision-specific rules.

Gateway compatibility filters must return the incoming resolved value unchanged when the gateway does not match. On partial resolution or failure, preserve all incoming fields and add only values that were resolved successfully.
