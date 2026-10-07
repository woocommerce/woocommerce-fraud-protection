# Dependency Injection

## Table of Contents

- [Standard DI Pattern for `src/` Classes](#standard-di-pattern-for-src-classes)
- [Registering Hooks: `register()`](#registering-hooks-register)
- [Using the Container to Get Instances](#using-the-container-to-get-instances)
- [Global Functions Through `LegacyProxy`](#global-functions-through-legacyproxy)
- [Why Use Dependency Injection?](#why-use-dependency-injection)

## Standard DI Pattern for `src/` Classes

The plugin's classes are resolved from WooCommerce's dependency-injection container (`wc_get_container()`), which resolves classes under the `Automattic\WooCommerce\` namespace without explicit registration. That is why the plugin's root namespace is `Automattic\WooCommerce\`.

Dependencies are injected via a `final public init()` method with an `@internal` annotation (blank comment lines before and after). Constructors must not have required parameters. `phpcs.xml` enforces this through `WooCommerce.Functions.InternalInjectionMethod` for everything under `src/`.

**Example:**

```php
namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

class PayForOrderProtector {
    private SessionVerifier $session_verifier;
    private BlockedSessionMessage $blocked_session_message;

    /**
     * Initialize with dependencies.
     *
     * @internal
     *
     * @param SessionVerifier       $session_verifier        The session verifier instance.
     * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
     */
    final public function init( SessionVerifier $session_verifier, BlockedSessionMessage $blocked_session_message ): void {
        $this->session_verifier        = $session_verifier;
        $this->blocked_session_message = $blocked_session_message;
    }
}
```

## Registering Hooks: `register()`

An application component registers its hooks in a public `register()` method, never in the constructor or in `init()`. `FraudProtectionController` owns component registration:

- The bootstrap resolves the controller on `woocommerce_loaded` (`PluginInitializer::handle_woocommerce_loaded()`).
- `FraudProtectionController::register()` checks the feature gate first, then registers the gateway compatibility classes (`Compat/`) at `woocommerce_loaded`.
- First-party components are registered from `FraudProtectionController::handle_init()` on WordPress `init`.
- Process-specific entry points that must stay outside the feature gate, such as the WP-CLI commands, register from `PluginInitializer`.

To add a new component:

1. Give it an `init()` for its dependencies and a `register()` that adds its hooks.
2. Inject it into `FraudProtectionController::init()` and store it in a property.
3. Call `$this->your_component->register()` from `handle_init()` (or from `register_compat_layers()` for a gateway compatibility class), following the timing of the nearest existing component.

Do not repeat the feature gate inside components. Keep it at the start of `FraudProtectionController::register()`.

## Using the Container to Get Instances

`wc_get_container()->get( ClassName::class )` returns the same instance every time (singleton). Use it for stateful services; the public services (`SessionVerifier`, `FraudProtectionReporter`, `BlackboxScriptHandler`) are always resolved this way, as the "Public API" section of `README.md` documents.

```php
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

$verifier = wc_get_container()->get( SessionVerifier::class );
```

Do not use the container for value objects. The public DTOs have public constructors (`BlockedSessionMessage`, `PaymentMethodData`, `SuppliedDecision`) or static factories (`ReportContextData::from_array()`, `PaymentInstrumentData::from_array()` / `::empty()`), and enums are used as cases. Follow each DTO's existing constructor or factory contract.

Prefer injecting a dependency through `init()` over calling `wc_get_container()` inside a method. Resolve lazily from the container only when the dependency must not be instantiated at wiring time (the controller does this for the REST controllers and the settings page behind the merchant-facing feature gate).

## Global Functions Through `LegacyProxy`

When a class must call a global function that tests need to intercept (for example `error_log()`), inject WooCommerce's `Automattic\WooCommerce\Proxies\LegacyProxy` and call the function through it, as `FraudProtectionLogger` and `SchemaManager` do. Tests then mock the call with `register_legacy_proxy_function_mocks()`.

## Why Use Dependency Injection?

- Easy mocking in tests: construct the class with `new`, then call `init()` with mocks
- Swap dependencies without code changes
- Explicit dependencies in signature
