<?php
/**
 * SettingsRestControllerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSettingUpdater;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsRestController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for SettingsRestController.
 */
class SettingsRestControllerTest extends \WC_REST_Unit_Test_Case {

	private const OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection';

	/** @var AutomaticProtectionSetting */
	private $setting;

	/** @var SessionEventStore&\PHPUnit\Framework\MockObject\MockObject */
	private $event_store;

	/** @var AutomaticProtectionSettingUpdater */
	private $updater;

	/** @var array{recommended_for_blocking: int, blocked_automatically: int, allowed_by_rules: int, blocked_by_rules: int} */
	private $performance_counts;

	/**
	 * The System Under Test.
	 *
	 * @var SettingsRestController
	 */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		$this->setting = new AutomaticProtectionSetting();
		$this->setting->reset();
		$this->performance_counts = array(
			'recommended_for_blocking' => 0,
			'blocked_automatically'     => 0,
			'allowed_by_rules'          => 0,
			'blocked_by_rules'          => 0,
		);
		$this->event_store = $this->createMock( SessionEventStore::class );
		$this->event_store->method( 'get_performance_counts' )->willReturnCallback( fn() => $this->performance_counts );
		$this->updater = new AutomaticProtectionSettingUpdater();
		$this->updater->init( $this->setting, $this->createMock( SettingsTelemetry::class ), $this->createMock( FraudProtectionLogger::class ) );
		$this->sut = new SettingsRestController();
		$this->sut->init( $this->setting, $this->updater, $this->event_store );
		$this->sut->register_routes();
		wp_set_current_user( 1 );
	}

	/**
	 * @testdox An authorized read returns the disabled default without writing it.
	 */
	public function test_get_returns_effective_default_without_write(): void {
		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'automatic_protection' => false,
				'performance'          => $this->performance_counts,
			),
			$response->get_data()
		);
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * @testdox An authorized read returns the recorded performance counts.
	 */
	public function test_get_returns_performance_counts(): void {
		$this->performance_counts = array(
			'recommended_for_blocking' => 12,
			'blocked_automatically'     => 3,
			'allowed_by_rules'          => 4,
			'blocked_by_rules'          => 5,
		);

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->performance_counts, $response->get_data()['performance'] );
	}

	/**
	 * @testdox An enabled update stores and returns the setting.
	 */
	public function test_post_stores_enabled_setting(): void {
		$this->event_store->expects( $this->never() )->method( 'get_performance_counts' );

		$response = $this->server->dispatch( $this->post_request( array( 'automatic_protection' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => true ), $response->get_data() );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox A disabled update stores and returns the setting.
	 */
	public function test_post_stores_explicit_disabled_choice(): void {
		$this->setting->set_enabled( true );

		$response = $this->server->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => false ), $response->get_data() );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox A performance query failure returns the generic settings load error.
	 */
	public function test_get_returns_error_when_performance_query_fails(): void {
		$event_store = $this->createMock( SessionEventStore::class );
		$event_store->method( 'get_performance_counts' )->willThrowException( new \RuntimeException( 'Database details' ) );
		$this->sut->init( $this->setting, $this->updater, $event_store );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_settings_not_loaded', $response->get_data()['code'] );
		$this->assertSame( 'The fraud prevention settings could not be loaded.', $response->get_data()['message'] );
	}

	/**
	 * @testdox Missing and invalid values do not change the setting.
	 *
	 * @dataProvider invalid_request_provider
	 *
	 * @param array<string, mixed> $data Request body.
	 */
	public function test_invalid_requests_do_not_change_setting( array $data ): void {
		$response = $this->server->dispatch( $this->post_request( $data ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * Provide invalid settings requests.
	 *
	 * @return array<string, array{array<string, mixed>}>
	 */
	public function invalid_request_provider(): array {
		return array(
			'missing setting' => array( array() ),
			'invalid value'   => array( array( 'automatic_protection' => 'invalid' ) ),
		);
	}

	/**
	 * @testdox A storage failure returns an error.
	 */
	public function test_storage_failure_returns_error(): void {
		add_filter( 'pre_update_option_' . self::OPTION_NAME, '__return_false' );

		$response = $this->server->dispatch( $this->post_request( array( 'automatic_protection' => true ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_setting_not_saved', $response->get_data()['code'] );
	}

	/**
	 * @testdox Unauthenticated and unauthorized users cannot read or update settings.
	 */
	public function test_permissions_require_woocommerce_management(): void {
		$this->setting->set_enabled( true );

		wp_set_current_user( 0 );
		$unauthenticated_get  = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );
		$unauthenticated_post = $this->server->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$customer_id = wc_create_new_customer( 'settings-customer@example.com', 'settings-customer', 'password' );
		wp_set_current_user( $customer_id );
		$unauthorized_get  = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );
		$unauthorized_post = $this->server->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$this->assertSame( 401, $unauthenticated_get->get_status() );
		$this->assertSame( 401, $unauthenticated_post->get_status() );
		$this->assertSame( 403, $unauthorized_get->get_status() );
		$this->assertSame( 403, $unauthorized_post->get_status() );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
	}

	/**
	 * Create a JSON settings update request.
	 *
	 * @param array<string, mixed> $data Request body.
	 */
	private function post_request( array $data ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $data ) );

		return $request;
	}
}
