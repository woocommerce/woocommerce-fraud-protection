<?php
/**
 * SettingsRestControllerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSettingUpdater;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsRestController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for SettingsRestController.
 */
class SettingsRestControllerTest extends FraudProtectionUnitTestCase {

	private const OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection';

	/** @var AutomaticProtectionSetting */
	private $setting;

	/**
	 * The System Under Test.
	 *
	 * @var SettingsRestController
	 */
	private $sut;

	/**
	 * Original REST server global.
	 *
	 * @var array{exists: bool, value: mixed}
	 */
	private array $original_rest_server;

	public function setUp(): void {
		parent::setUp();
		$this->original_rest_server = array(
			'exists' => array_key_exists( 'wp_rest_server', $GLOBALS ),
			'value'  => $GLOBALS['wp_rest_server'] ?? null,
		);
		$this->setting = new AutomaticProtectionSetting();
		$this->setting->reset();
		$updater = new AutomaticProtectionSettingUpdater();
		$updater->init( $this->setting, $this->createMock( SettingsTelemetry::class ), $this->createMock( FraudProtectionLogger::class ) );
		$this->register_controller( $updater );
		wp_set_current_user( 1 );
	}

	public function tearDown(): void {
		if ( $this->original_rest_server['exists'] ) {
			$GLOBALS['wp_rest_server'] = $this->original_rest_server['value'];
		} else {
			unset( $GLOBALS['wp_rest_server'] );
		}
		parent::tearDown();
	}

	/**
	 * @testdox An authorized read returns the disabled default without writing it.
	 */
	public function test_get_returns_effective_default_without_write(): void {
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => false ), $response->get_data() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * @testdox An enabled update stores and returns the setting.
	 */
	public function test_post_stores_enabled_setting(): void {
		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => true ), $response->get_data() );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox A disabled update stores and returns the setting.
	 */
	public function test_post_stores_explicit_disabled_choice(): void {
		$this->setting->set_enabled( true );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => false ), $response->get_data() );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox Missing and invalid values do not change the setting.
	 *
	 * @dataProvider invalid_request_provider
	 *
	 * @param array<string, mixed> $data Request body.
	 */
	public function test_invalid_requests_do_not_change_setting( array $data ): void {
		$response = rest_get_server()->dispatch( $this->post_request( $data ) );

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
		$updater = $this->createMock( AutomaticProtectionSettingUpdater::class );
		$updater->method( 'set_enabled' )->willReturn( false );
		$this->register_controller( $updater );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => true ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_setting_not_saved', $response->get_data()['code'] );
	}

	/**
	 * @testdox Unauthenticated and unauthorized users cannot read or update settings.
	 */
	public function test_permissions_require_woocommerce_management(): void {
		$this->setting->set_enabled( true );

		wp_set_current_user( 0 );
		$unauthenticated_get  = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );
		$unauthenticated_post = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$customer_id = wc_create_new_customer( 'settings-customer@example.com', 'settings-customer', 'password' );
		wp_set_current_user( $customer_id );
		$unauthorized_get  = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );
		$unauthorized_post = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$this->assertSame( 401, $unauthenticated_get->get_status() );
		$this->assertSame( 401, $unauthenticated_post->get_status() );
		$this->assertSame( 403, $unauthorized_get->get_status() );
		$this->assertSame( 403, $unauthorized_post->get_status() );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
	}

	/**
	 * Register the controller with an updater.
	 *
	 * @param AutomaticProtectionSettingUpdater $updater Setting updater.
	 */
	private function register_controller( AutomaticProtectionSettingUpdater $updater ): void {
		if ( isset( $this->sut ) ) {
			remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
		}

		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		$this->sut                 = new SettingsRestController();
		$this->sut->init( $this->setting, $updater );
		$this->sut->register();
		do_action( 'rest_api_init', rest_get_server() );
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
