<?php
/**
 * KustomScriptCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\KustomScriptCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;

/**
 * Tests for the KustomScriptCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\KustomScriptCompat
 */
class KustomScriptCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var KustomScriptCompat
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$protector = new ShortcodeCheckoutProtector();
		$protector->init(
			$this->createMock( SessionVerifier::class ),
			$this->createMock( BlockedSessionMessage::class ),
			$this->make_blackbox_script_handler()
		);

		$this->sut = new KustomScriptCompat();
		$this->sut->init( $protector );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'kco_wc_before_snippet' );
		$this->reset_fraud_protection_scripts();

		parent::tearDown();
	}

	/**
	 * @testdox Rendering Kustom's embedded checkout loads the classic checkout script.
	 */
	public function test_kustom_checkout_render_enqueues_classic_checkout_script(): void {
		$this->mock_jetpack_blog_id( 12345 );
		$this->sut->register();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		do_action( 'kco_wc_before_snippet' );

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'wc-fraud-protection-shortcode-checkout', 'enqueued' ) );
	}
}
