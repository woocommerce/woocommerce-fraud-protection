<?php
/**
 * SettingsRestControllerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsRestController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for SettingsRestController.
 */
class SettingsRestControllerTest extends FraudProtectionUnitTestCase {

	private const OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection';

	/**
	 * Automatic protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private $setting;

	/**
	 * Settings telemetry mock.
	 *
	 * @var SettingsTelemetry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $telemetry;

	/**
	 * REST controller under test.
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
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		$this->setting   = new AutomaticProtectionSetting();
		$this->telemetry = $this->createMock( SettingsTelemetry::class );
		$this->setting->reset();
		$this->sut = new SettingsRestController();
		$this->sut->init( $this->setting, $this->telemetry );
		$this->sut->register();
		do_action( 'rest_api_init', rest_get_server() );
		wp_set_current_user( 1 );
	}

	public function tearDown(): void {
		$this->setting->reset();
		remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
		wp_set_current_user( 0 );
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
	 * @testdox A partial update stores the Boolean and records a settings-channel transition.
	 */
	public function test_post_updates_setting_and_records_transition(): void {
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( 'enabled', SettingsTelemetry::CHANNEL_SETTINGS );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => true ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => true ), $response->get_data() );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox Supported REST Boolean forms are normalized before storage.
	 *
	 * @dataProvider supported_enabled_values
	 *
	 * @param mixed $value Supported Boolean form.
	 */
	public function test_post_normalizes_supported_enabled_values( mixed $value ): void {
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( 'enabled', SettingsTelemetry::CHANNEL_SETTINGS );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => $value ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => true ), $response->get_data() );
		$this->assertSame( 'yes', get_option( self::OPTION_NAME ) );
	}

	/**
	 * Supported REST values that mean enabled.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function supported_enabled_values(): array {
		return array(
			'string true' => array( 'true' ),
			'integer one' => array( 1 ),
		);
	}

	/**
	 * @testdox Disabling automatic protection stores an explicit choice and records the transition.
	 */
	public function test_post_stores_explicit_disabled_choice(): void {
		$this->setting->set_enabled( true );
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( 'disabled', SettingsTelemetry::CHANNEL_SETTINGS );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'automatic_protection' => false ), $response->get_data() );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox An unchanged update succeeds without recording another action.
	 */
	public function test_unchanged_post_records_no_transition(): void {
		$this->setting->set_enabled( false );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => false ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no', get_option( self::OPTION_NAME ) );
	}

	/**
	 * @testdox Empty and invalid requests do not change the setting.
	 */
	public function test_invalid_requests_do_not_change_setting(): void {
		$empty   = rest_get_server()->dispatch( $this->post_request( array() ) );
		$invalid = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => 'invalid' ) ) );

		$this->assertSame( 400, $empty->get_status() );
		$this->assertSame( 400, $invalid->get_status() );
		$this->assertNull( get_option( self::OPTION_NAME, null ) );
	}

	/**
	 * @testdox A storage failure returns an error and records no action.
	 */
	public function test_storage_failure_returns_error_without_telemetry(): void {
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		remove_action( 'rest_api_init', array( $this->sut, 'register_routes' ) );
		$setting                   = $this->createMock( AutomaticProtectionSetting::class );
		$setting->method( 'get_stored_status' )->willReturn( AutomaticProtectionSetting::STATUS_DEFAULT_DISABLED );
		$setting->method( 'set_enabled' )->willReturn( false );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$controller = new SettingsRestController();
		$controller->init( $setting, $this->telemetry );
		$controller->register();
		do_action( 'rest_api_init', rest_get_server() );

		$response = rest_get_server()->dispatch( $this->post_request( array( 'automatic_protection' => true ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_setting_not_saved', $response->get_data()['code'] );
	}

	/**
	 * @testdox Unauthenticated and unauthorized users cannot read settings.
	 */
	public function test_permissions_require_woocommerce_management(): void {
		wp_set_current_user( 0 );
		$unauthenticated = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );

		$customer_id = wc_create_new_customer( 'settings-customer@example.com', 'settings-customer', 'password' );
		wp_set_current_user( $customer_id );
		$unauthorized = rest_get_server()->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/settings' ) );

		$this->assertSame( 401, $unauthenticated->get_status() );
		$this->assertSame( 403, $unauthorized->get_status() );
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
