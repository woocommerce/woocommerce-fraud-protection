<?php
/**
 * BlackboxScriptHandlerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;

/**
 * Tests for BlackboxScriptHandler.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler
 */
class BlackboxScriptHandlerTest extends FraudProtectionUnitTestCase {

	/**
	 * The system under test.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $sut;

	/**
	 * Mock session identity manager.
	 *
	 * @var SessionIdentityManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_identity_manager;

	/**
	 * Jetpack option filters added by this test.
	 *
	 * @var callable[]
	 */
	private array $jetpack_option_filters = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->jetpack_option_filters = array();

		$this->session_identity_manager = $this->createMock( SessionIdentityManager::class );
		$this->session_identity_manager->method( 'get_identity_id' )->willReturn( 'mock-session-id' );

		$this->sut = new BlackboxScriptHandler();
		$this->sut->init( $this->session_identity_manager );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_is_order_received_page', '__return_true' );
		remove_filter( 'pre_option_jetpack_options', '__return_empty_array' );
		foreach ( $this->jetpack_option_filters as $filter ) {
			remove_filter( 'jetpack_options', $filter, 10 );
		}
		$this->jetpack_option_filters = array();
		delete_transient( 'wc_fraud_protection_missing_blog_id_log' );
		$this->reset_fraud_protection_scripts();
		$this->leave_excluded_render_context();

		global $wp;
		unset( $wp->query_vars['order-pay'] );
		unset( $wp->query_vars['order-received'] );

		parent::tearDown();
	}

	/**
	 * @testdox request_scripts() enqueues and configures the shared SDK and init script.
	 */
	public function test_request_scripts_enqueues_shared_scripts(): void {
		$this->mock_jetpack_blog_id( 42 );

		$this->assertTrue( $this->sut->request_scripts() );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'registered' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );

		$init_script = wp_scripts()->query( 'wc-fraud-protection-blackbox-init', 'registered' );
		$this->assertNotFalse( $init_script );
		$this->assertSame(
			plugins_url( 'assets/js/blackbox-init.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			$init_script->src
		);
		$this->assertSame( array( 'wc-fraud-protection-blackbox' ), $init_script->deps );

		$data = (string) wp_scripts()->get_data( 'wc-fraud-protection-blackbox-init', 'data' );
		$this->assertStringContainsString( '"apiKey":"woo:42"', $data );
		$this->assertStringContainsString( '"identityKey":"mock-session-id"', $data );
		$this->assertStringContainsString( '"timeout":3000', $data );
		$this->assertStringContainsString( '"sessionIdField":"wc_fraud_protection_session_id"', $data );
	}

	/**
	 * @testdox A managed URL correction registered after bootstrap applies when scripts are requested.
	 */
	public function test_request_scripts_resolves_init_url_after_a_late_url_correction(): void {
		$this->mock_jetpack_blog_id( 42 );
		$correction = function ( $url, $path, $plugin_file ) {
			unset( $plugin_file );

			return 'assets/js/blackbox-init.js' === $path ? 'https://managed.example.test/woocommerce-fraud-protection/assets/js/blackbox-init.js' : $url;
		};
		add_filter( 'plugins_url', $correction, 10, 3 );

		$this->assertTrue( $this->sut->request_scripts() );
		$init_script = wp_scripts()->query( 'wc-fraud-protection-blackbox-init', 'registered' );
		$this->assertNotFalse( $init_script );
		$this->assertSame( 'https://managed.example.test/woocommerce-fraud-protection/assets/js/blackbox-init.js', $init_script->src );
	}

	/**
	 * @testdox Repeated requests use one SDK registration and one init configuration.
	 */
	public function test_request_scripts_is_idempotent(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$identity_manager = $this->createMock( SessionIdentityManager::class );
		$identity_manager->expects( $this->once() )->method( 'get_identity_id' )->willReturn( 'one-identity' );
		$handler = new BlackboxScriptHandler();
		$handler->init( $identity_manager );

		$this->assertTrue( $handler->request_scripts() );
		$first_data = wp_scripts()->get_data( 'wc-fraud-protection-blackbox-init', 'data' );

		$this->assertTrue( $handler->request_scripts() );

		$this->assertSame( $first_data, wp_scripts()->get_data( 'wc-fraud-protection-blackbox-init', 'data' ) );
		$this->assertSame( 1, substr_count( (string) $first_data, 'var wcFraudProtection' ) );
		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-blackbox'] );
		$this->assertSame( 1, array_count_values( wp_scripts()->queue )['wc-fraud-protection-blackbox-init'] );
	}

	/**
	 * @testdox A queued consumer dependency does not prevent request_scripts() from configuring the init handle.
	 */
	public function test_request_scripts_configures_an_unregistered_init_dependency(): void {
		$this->mock_jetpack_blog_id( 12345 );
		wp_register_script(
			'wc-fraud-protection-extension-flow',
			'https://example.com/extension-flow.js',
			array( 'wc-fraud-protection-blackbox-init' ),
			'1.0',
			true
		);
		wp_enqueue_script( 'wc-fraud-protection-extension-flow' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'registered' ) );
		$this->assertTrue( $this->sut->request_scripts() );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'registered' ) );
		$this->assertStringContainsString(
			'"apiKey":"woo:12345"',
			(string) wp_scripts()->get_data( 'wc-fraud-protection-blackbox-init', 'data' )
		);
	}

	/**
	 * @testdox A missing blog ID is checked on every request_scripts() call and logged once per hour.
	 */
	public function test_missing_blog_id_is_checked_each_time_and_logged_once_per_hour(): void {
		$logging_spy = $this->spy_on_controller_logging();
		$option_reads = 0;
		$this->add_jetpack_option_filter(
			function ( $value, string $name ) use ( &$option_reads ) {
				if ( 'id' === $name ) {
					++$option_reads;
					return false;
				}

				return $value;
			}
		);

		$this->assertFalse( $this->sut->request_scripts() );
		$this->assertFalse( $this->sut->request_scripts() );
		$this->assertFalse( $this->sut->request_scripts() );

		$this->assertSame( 3, $option_reads );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );

		$matching_logs = array_filter(
			$logging_spy->entries,
			function ( array $entry ): bool {
				return str_contains( $entry['message'], 'Jetpack blog ID not available' );
			}
		);
		$this->assertCount( 1, $matching_logs );
		$this->assertTrue( reset( $matching_logs )['forwarded'] );
		$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, get_option( '_transient_timeout_wc_fraud_protection_missing_blog_id_log' ) );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, get_option( '_transient_timeout_wc_fraud_protection_missing_blog_id_log' ) );
	}

	/**
	 * @testdox A handler recovers immediately when the blog ID becomes available after a miss.
	 */
	public function test_missing_blog_id_check_recovers_in_the_same_request(): void {
		add_filter( 'pre_option_jetpack_options', '__return_empty_array' );
		$this->assertFalse( $this->sut->request_scripts() );

		remove_filter( 'pre_option_jetpack_options', '__return_empty_array' );
		$this->mock_jetpack_blog_id( 12345 );

		$this->assertTrue( $this->sut->request_scripts(), 'The next check must see the newly available Jetpack blog ID.' );
	}

	/**
	 * @testdox The missing-blog-ID log is emitted again after the hourly throttle is cleared.
	 */
	public function test_missing_blog_id_log_recovers_after_throttle_expiry(): void {
		$logging_spy = $this->spy_on_controller_logging();
		add_filter( 'pre_option_jetpack_options', '__return_empty_array' );

		$this->assertFalse( $this->sut->request_scripts() );
		$this->assertFalse( $this->sut->request_scripts() );
		delete_transient( 'wc_fraud_protection_missing_blog_id_log' );
		$this->assertFalse( $this->sut->request_scripts() );

		$matching_logs = array_filter(
			$logging_spy->entries,
			function ( array $entry ): bool {
				return str_contains( $entry['message'], 'Jetpack blog ID not available' );
			}
		);
		$this->assertCount( 2, $matching_logs );
	}

	/**
	 * @testdox Invalid Jetpack blog IDs make the shared scripts unavailable.
	 *
	 * @dataProvider invalid_blog_id_provider
	 *
	 * @param mixed $blog_id Invalid Jetpack blog ID.
	 */
	public function test_invalid_blog_id_prevents_scripts( $blog_id ): void {
		$this->add_jetpack_option_filter(
			function ( $value, string $name ) use ( $blog_id ) {
				return 'id' === $name ? $blog_id : $value;
			}
		);

		$this->assertFalse( $this->sut->request_scripts() );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
	}

	/**
	 * Invalid Jetpack blog IDs.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function invalid_blog_id_provider(): array {
		return array(
			'zero'        => array( 0 ),
			'negative'    => array( -1 ),
			'non-numeric' => array( 'not-a-blog-id' ),
		);
	}

	/**
	 * @testdox request_scripts() declines excluded render contexts.
	 *
	 * @dataProvider excluded_render_context_provider
	 *
	 * @param callable $enter_context Puts the request into a non-purchase context.
	 */
	public function test_request_scripts_declines_excluded_render_contexts( callable $enter_context ): void {
		$this->mock_jetpack_blog_id( 12345 );
		$enter_context( $this );

		$this->assertFalse( $this->sut->request_scripts() );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
	}

	/**
	 * @testdox request_scripts() declines order confirmation pages without a pay form.
	 */
	public function test_request_scripts_declines_order_confirmation_page(): void {
		$this->mock_jetpack_blog_id( 12345 );
		add_filter( 'woocommerce_is_order_received_page', '__return_true' );

		$this->assertFalse( $this->sut->request_scripts() );
		$this->assertFalse( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
	}

	/**
	 * @testdox An anonymous preview query does not disable a real payment surface.
	 */
	public function test_anonymous_preview_query_does_not_disable_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$GLOBALS['wp_query']->is_preview = true;
		wp_set_current_user( 0 );

		$this->assertTrue( $this->sut->request_scripts() );
	}

	/**
	 * @testdox A wp-json substring in an ordinary URL does not disable a real payment surface.
	 */
	public function test_rest_prefix_in_query_string_does_not_disable_scripts(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->set_server_variables( array( 'REQUEST_URI' => '/checkout/?source=wp-json/' ) );

		$this->assertTrue( $this->sut->request_scripts() );
	}

	/**
	 * @testdox An order-received query value outside checkout does not disable a real form.
	 */
	public function test_order_received_query_value_outside_checkout_does_not_disable_scripts(): void {
		global $wp;

		$this->mock_jetpack_blog_id( 12345 );
		$wp->query_vars['order-received'] = '123';

		$this->assertFalse( is_order_received_page() );
		$this->assertTrue( $this->sut->request_scripts() );
	}

	/**
	 * Render contexts that must not load payment telemetry.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public function excluded_render_context_provider(): array {
		return array(
			'site editor'         => array(
				function (): void {
					set_current_screen( 'site-editor' );
				},
			),
			'REST/SSR render'     => array(
				function (): void {
					add_filter( 'wp_is_rest_endpoint', '__return_true' );
				},
			),
			'editor post preview' => array(
				function ( self $test ): void {
					$test->enter_editor_preview();
				},
			),
			'customizer preview'  => array(
				function ( self $test ): void {
					$test->enter_customizer_preview();
				},
			),
		);
	}

	/**
	 * Put the request into a Customizer preview.
	 */
	private function enter_customizer_preview(): void {
		require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';

		$manager_class = new \ReflectionClass( \WP_Customize_Manager::class );
		$manager       = $manager_class->newInstanceWithoutConstructor();
		$previewing    = $manager_class->getProperty( 'previewing' );
		$previewing->setAccessible( true );
		$previewing->setValue( $manager, true );

		$GLOBALS['wp_customize'] = $manager;
	}

	/**
	 * Put the request into an authorized post preview.
	 */
	private function enter_editor_preview(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		if ( 0 === get_queried_object_id() ) {
			$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

			$GLOBALS['wp_query']->queried_object    = get_post( $page_id );
			$GLOBALS['wp_query']->queried_object_id = $page_id;
		}

		$GLOBALS['wp_query']->is_preview = true;
	}

	/**
	 * Restore the frontend render context.
	 */
	private function leave_excluded_render_context(): void {
		remove_filter( 'wp_is_rest_endpoint', '__return_true' );
		$GLOBALS['wp_query']->is_preview        = false;
		$GLOBALS['wp_query']->queried_object    = null;
		$GLOBALS['wp_query']->queried_object_id = 0;
		unset( $GLOBALS['wp_customize'] );
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
	}

	/**
	 * Add a Jetpack option filter and track it for cleanup.
	 *
	 * @param callable $filter Filter callback.
	 */
	private function add_jetpack_option_filter( callable $filter ): void {
		add_filter( 'jetpack_options', $filter, 10, 2 );
		$this->jetpack_option_filters[] = $filter;
	}
}
