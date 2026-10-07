# Unit Testing Conventions

Read the "Tests" section of `AGENTS.md` first. In short: test the plugin's observable behavior through real WooCommerce flows where practical, mock an external request or collaborator at its direct boundary, do not use an unrelated hook only to control test setup, and remove every hook and option a test creates.

## Table of Contents

- [Complete Test File Template](#complete-test-file-template)
- [Test File Naming and Location](#test-file-naming-and-location)
- [System Under Test Variable](#system-under-test-variable)
- [Test Method Documentation](#test-method-documentation)
- [Comments in Tests](#comments-in-tests)
- [Test Configuration](#test-configuration)
- [Base Test Case Helpers](#base-test-case-helpers)
- [Mocking](#mocking)
- [Preferred Real Flows](#preferred-real-flows)
- [General Testing Best Practices](#general-testing-best-practices)

## Complete Test File Template

Use this template when creating new test files. It shows all conventions applied together:

```php
<?php
/**
 * SessionRateLimiterTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionRateLimiter;

/**
 * Tests for the SessionRateLimiter class.
 */
class SessionRateLimiterTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionRateLimiter
	 */
	private $sut;

	/**
	 * Mock session event store.
	 *
	 * @var SessionEventStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_store;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->event_store = $this->createMock( SessionEventStore::class );
		$this->sut         = new SessionRateLimiter();
		$this->sut->init( $this->event_store );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_fraud_protection_rate_limit' );
		delete_option( 'wc_fraud_protection_rate_limit' );
		parent::tearDown();
	}

	/**
	 * @testdox Allows the first attempt for a new session.
	 */
	public function test_allows_first_attempt(): void {
		$this->event_store->method( 'count_attempts' )->willReturn( 0 );

		$result = $this->sut->is_allowed( 'session-id' );

		$this->assertTrue( $result, 'The first attempt should be allowed' );
	}

	/**
	 * @testdox Logs a warning and fails open when the store throws.
	 */
	public function test_fails_open_when_store_throws(): void {
		$this->event_store->method( 'count_attempts' )->willThrowException( new \RuntimeException( 'db down' ) );

		$result = $this->sut->is_allowed( 'session-id' );

		$this->assertTrue( $result, 'A storage failure must not block the attempt' );
		$this->assertLogged( 'warning', 'rate limit' );
	}
}
```

### Key Elements

| Element | Requirement |
| ------- | ----------- |
| File docblock | `{ClassName}Test class file.` before `declare` (same header order as source files) |
| `declare( strict_types = 1 )` | Required |
| Namespace | `Automattic\WooCommerce\Tests\{path}` mirroring the source location under `src/` |
| Base class | Extend `FraudProtectionUnitTestCase` (`Automattic\WooCommerce\FraudProtection\Tests`), which extends WooCommerce Core's `WC_Unit_Test_Case` |
| SUT variable | Use `$sut` with docblock "The System Under Test." |
| Dependencies | Create the SUT with `new`, then call `init()` with mocks or real collaborators |
| Test docblock | Use `@testdox` with a sentence ending in `.` |
| Return type | Use `void` for test methods |
| Cleanup | Restore globals and remove hooks and options in `tearDown()` before `parent::tearDown()` |
| Assertion messages | Include helpful context for failures |

## Test File Naming and Location

| Source | Test |
| ------ | ---- |
| `src/FraudProtection/{path}/{Name}.php` | `tests/php/src/FraudProtection/{path}/{Name}Test.php` |
| `src/Internal/FraudProtectionPlugin/{path}/{Name}.php` | `tests/php/src/Internal/FraudProtectionPlugin/{path}/{Name}Test.php` |

Test class: same name as the source class plus the `Test` suffix. Integration tests that span several components use a descriptive name ending in `IntegrationTest`.

Test support classes (fakes, stubs, fixtures) live in `tests/php/Support/` under `Automattic\WooCommerce\FraudProtection\Tests\Support`, which Composer autoloads (`autoload-dev`). Test classes themselves are discovered by PHPUnit's directory scan and are not autoloaded. Third-party class stubs for tests live in `tests/php/stubs/`. Reuse the existing support classes and data providers before adding new fixtures or helpers.

## System Under Test Variable

Use `$sut` with docblock "The System Under Test."

```php
/**
 * The System Under Test.
 *
 * @var RuleEvaluator
 */
private $sut;
```

## Test Method Documentation

When adding or modifying a unit test method, the part of the docblock that describes the test must be prepended with `@testdox`. End the sentence with `.` for compliance with linting rules.

**Example:**

```php
/**
 * @testdox Returns the matched rule's action instead of the service decision.
 */
public function test_rule_overrides_service_decision(): void {
    // ...
}

/**
 * @testdox Keeps the decision that entered the filter when a callback throws.
 * @dataProvider decision_provider
 *
 * @param FraudDecision $decision The decision that enters the filter.
 */
public function test_throwing_filter_keeps_decision( FraudDecision $decision ): void {
    // ...
}
```

## Comments in Tests

**Avoid over-commenting tests.** Test names and assertion messages should explain intent.

**Good - Self-explanatory:**

```php
/**
 * @testdox Fails open when the verify response cannot be parsed.
 */
public function test_fails_open_on_unparseable_response(): void {
    $this->api_client->method( 'verify' )->willReturn( VerifyResult::fail_open() );

    $decision = $this->sut->verify_session( 'session-id', 'blocks_checkout', 0, array() );

    $this->assertSame( FraudDecision::Allow, $decision, 'A parse failure must fail open' );
}
```

**Avoid - Arrange/Act/Assert comments:**

```php
// Don't add these structural comments
// Arrange
$decision = $this->build_decision();

// Act
$result = $this->sut->apply( $decision );

// Assert
$this->assertTrue( $result );
```

Use blank lines for visual separation instead. The test structure should be self-evident.

**When comments ARE useful in tests:**

- Explaining non-obvious setup: `// WooCommerce caches is_checkout() per request; reset it before go_to().`
- Documenting known issues or workarounds in WordPress or WooCommerce
- Clarifying a business rule the assertion depends on

## Test Configuration

- Configuration file: `phpunit.xml` at the repository root
- Bootstrap: `tests/bootstrap.php` (loads the WordPress test library and the WooCommerce test framework; `WC_DIR` points to a WooCommerce checkout when the bootstrap cannot find one)
- Suite: every `*Test.php` file under `tests/php`
- How to run: see running-tests.md in the `woocommerce-dev-cycle` skill

## Base Test Case Helpers

`FraudProtectionUnitTestCase` provides, and restores automatically in `tearDown()`:

- **Logging spy**: installed in `setUp()` by default, so every `FraudProtectionController::log()` call is captured in memory. Assert with `assertLogged( $level, $substring, $expected_context = null, $forwarded = null )`. Override `uses_logging_spy()` to return `false` only when a test exercises the real logging path.
- **Platform-log forwarding**: `get_forwarded_platform_logs()` returns the raw `error_log()` lines forwarded during the test (`error_log` is mocked through the legacy proxy).
- **Jetpack connection**: `mock_jetpack_blog_id( int $blog_id )`.
- **Server variables**: `set_server_variables( array )` and `unset_server_variables( array )`.
- **Scripts**: `make_blackbox_script_handler()` builds a working `BlackboxScriptHandler`; `reset_fraud_protection_scripts()` dequeues and deregisters every plugin script handle so enqueue state does not leak between tests.
- **WooCommerce state**: the session and cart objects, the cart and checkout page caches, and the legacy proxy mocks are reset after each test.

Do not use the `woocommerce_logging_class` filter or a fake `WC_Logger` to assert on logging; the spy already covers the plugin's log facade.

## Mocking

- Mock collaborators with `$this->createMock()` and pass them through `init()`.
- Mock outbound HTTP at its boundary. Consumers of `ApiClient` mock `ApiClient`. Tests of `ApiClient` itself stub its transport seam (`jetpack_remote_request()`) with `getMockBuilder()->onlyMethods()`. Use the `pre_http_request` filter only for code that calls the WordPress HTTP API directly.
- Global functions that must be intercepted go through `LegacyProxy` in production code and `register_legacy_proxy_function_mocks()` in tests (see dependency-injection.md).
- Mock at the direct boundary of the code under test. Do not use an unrelated WordPress or WooCommerce filter only to control setup.
- Prefer the existing fakes in `tests/php/Support/` (for example the PayPal order fakes and the session event fixtures) over new ad-hoc doubles.

## Preferred Real Flows

Prefer real WooCommerce flows over asserting implementation details:

- Payment gateways through `WC()->payment_gateways()->get_available_payment_gateways()`
- REST requests through `rest_get_server()->dispatch( $request )`
- Actions and rendered output through the real hook (`do_action()`)

Tests must cover behavior owned by this plugin. Avoid tests that only repeat WooCommerce behavior.

## General Testing Best Practices

1. **Always run tests after making changes** to verify functionality
2. **Use specific test filters** during development (see running-tests.md in the `woocommerce-dev-cycle` skill)
3. **Write descriptive test names** that explain what is being tested
4. **Use data providers** for testing multiple scenarios with the same logic; give each case a descriptive key
5. **Include helpful assertion messages** for debugging when tests fail
6. **Test both the success path and the fail-open path**: transport failures, parsing failures, invalid decisions, and throwing filter callbacks must never produce a Block
7. **Clean up**: remove hooks and delete options created by the test
