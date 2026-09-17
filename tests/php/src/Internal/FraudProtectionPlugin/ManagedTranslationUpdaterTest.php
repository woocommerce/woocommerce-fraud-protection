<?php
/**
 * ManagedTranslationUpdaterTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

// These tests create and remove controlled translation files and ZIP archives.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_copy, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_flock, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ManagedTranslationUpdater;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use ZipArchive;

/**
 * Tests for ManagedTranslationUpdater.
 */
class ManagedTranslationUpdaterTest extends FraudProtectionUnitTestCase {

	private const LOCALE   = 'pt_BR';
	private const REVISION = '2026-09-17 12:00:00+00:00';
	private const PREFIX   = 'woocommerce-fraud-protection-' . self::LOCALE;

	/** @var ManagedTranslationUpdater */
	private $sut;

	/** @var array<string, mixed> */
	private $response;

	/** @var array<string, mixed> */
	private $request = array();

	/** @var string|null */
	private $download_source;

	/** @var string[] */
	private $temporary_paths = array();

	/** @var int */
	private $download_calls = 0;

	/** @var bool */
	private $download_fails = false;

	/** @var bool */
	private $unzip_fails = false;

	/** @var mixed */
	private $original_wp_filesystem;

	/** @var bool */
	private $had_wp_filesystem = false;

	/** Set up test fixtures. */
	public function setUp(): void {
		parent::setUp();
		$this->response               = $this->package_response();
		$this->request                = array();
		$this->download_source        = null;
		$this->temporary_paths        = array();
		$this->download_calls         = 0;
		$this->download_fails         = false;
		$this->unzip_fails            = false;
		$this->had_wp_filesystem      = array_key_exists( 'wp_filesystem', $GLOBALS );
		$this->original_wp_filesystem = $GLOBALS['wp_filesystem'] ?? null;
		$GLOBALS['wp_filesystem']     = $this->create_filesystem(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The test restores the original global in tearDown().

		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists'            => static fn( $class_name ) => class_exists( $class_name ),
				'get_current_blog_id'     => static fn() => 91,
				'get_locale'              => static fn() => self::LOCALE,
				'get_available_languages' => static fn() => array( self::LOCALE, 'de_DE', self::LOCALE, '../bad' ),
				'wp_next_scheduled'       => static fn() => false,
				'wp_schedule_event'       => static fn() => true,
				'WP_Filesystem'           => static fn() => true,
				'wp_remote_post'          => function ( $url, $args ) {
					$this->request = array(
						'url'  => $url,
						'args' => $args,
					);
					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( $this->response ),
					);
				},
				'download_url'            => function () {
					++$this->download_calls;
					if ( $this->download_fails || null === $this->download_source ) {
						return new \WP_Error( 'download_failed' );
					}
					$path = tempnam( sys_get_temp_dir(), 'wcfp-download-' );
					$this->assertIsString( $path );
					$this->assertTrue( copy( $this->download_source, $path ) );
					$this->temporary_paths[] = $path;
					return $path;
				},
				'unzip_file'              => function ( $path, $destination ) {
					if ( $this->unzip_fails ) {
						return new \WP_Error( 'unzip_failed' );
					}
					$zip = new ZipArchive();
					if ( true !== $zip->open( $path ) ) {
						return new \WP_Error( 'unzip_failed' );
					}
					$result = $zip->extractTo( $destination );
					$zip->close();
					return $result;
				},
			)
		);

		$this->sut = new ManagedTranslationUpdater();
		$this->sut->init( wc_get_container()->get( LegacyProxy::class ) );
	}

	/** Tear down test fixtures. */
	public function tearDown(): void {
		$this->remove_path( $this->translation_dir() . '/.woocommerce-fraud-protection-translation-91.lock' );
		$catalogs = glob( $this->translation_dir() . '/' . self::PREFIX . '*' );
		foreach ( false === $catalogs ? array() : $catalogs as $path ) {
			$this->remove_path( $path );
		}
		foreach ( $this->temporary_paths as $path ) {
			$this->remove_path( $path );
		}
		if ( $this->had_wp_filesystem ) {
			$GLOBALS['wp_filesystem'] = $this->original_wp_filesystem; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the global changed in setUp().
		} else {
			unset( $GLOBALS['wp_filesystem'] );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Registration schedules one due-now twice-daily event without making a request.
	 */
	public function test_register_schedules_without_network_request(): void {
		$scheduled = array();
		$this->register_legacy_proxy_function_mocks(
			array(
				'wp_next_scheduled' => static fn() => false,
				'wp_schedule_event' => function ( $timestamp, $schedule, $hook ) use ( &$scheduled ) {
					$scheduled = array( $timestamp, $schedule, $hook );
					return true;
				},
			)
		);

		$before = time();
		$this->sut->register();

		$this->assertSame( 'twicedaily', $scheduled[1] );
		$this->assertSame( 'woocommerce_fraud_protection_managed_translation_update', $scheduled[2] );
		$this->assertGreaterThanOrEqual( $before, $scheduled[0] );
		$this->assertSame( array(), $this->request );
	}

	/**
	 * @testdox Registration does not schedule a duplicate event.
	 */
	public function test_register_keeps_existing_cron_event(): void {
		$schedule_calls = 0;
		$this->register_legacy_proxy_function_mocks(
			array(
				'wp_next_scheduled' => static fn() => 1234567890,
				'wp_schedule_event' => function () use ( &$schedule_calls ) {
					++$schedule_calls;
					return true;
				},
			)
		);

		$this->sut->register();

		$this->assertSame( 0, $schedule_calls );
		$this->assertSame( array(), $this->request );
	}

	/**
	 * @testdox A valid pt_BR pack uses the latest request, accepts the resolved version, replaces catalogs, removes stale files, and then skips equal and older revisions.
	 */
	public function test_successful_update_and_revision_skip(): void {
		$this->write_file( $this->catalog_path( '.po' ), $this->po_contents( '2026-09-16 12:00:00+00:00' ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->write_file( $this->catalog_path( '-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json' ), 'stale json' );
		$this->write_file( $this->translation_dir() . '/other-plugin-' . self::LOCALE . '.mo', 'other plugin' );
		$this->temporary_paths[] = $this->translation_dir() . '/other-plugin-' . self::LOCALE . '.mo';
		$this->download_source   = $this->create_zip(
			array(
				self::PREFIX . '.po' => $this->po_contents( self::REVISION ),
				self::PREFIX . '.mo' => 'new runtime',
				self::PREFIX . '-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.json' => 'new json',
			)
		);

		$this->sut->update();

		$this->assertSame( 'https://translate.wordpress.com/api/translations-updates/woocommerce', $this->request['url'] );
		$this->assertSame(
			array(
				'locales' => array( self::LOCALE, 'de_DE' ),
				'plugins' => array( 'woocommerce-fraud-protection' => array( 'version' => 'latest' ) ),
			),
			json_decode( $this->request['args']['body'], true )
		);
		$this->assertSame( array( 'Content-Type: application/json' ), $this->request['args']['headers'] );
		$this->assertSame( 30, $this->request['args']['timeout'] );
		$this->assertSame( 'new runtime', file_get_contents( $this->catalog_path( '.mo' ) ) );
		$this->assertFileExists( $this->catalog_path( '-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.json' ) );
		$this->assertFileDoesNotExist( $this->catalog_path( '-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json' ) );
		$this->assertSame( 'other plugin', file_get_contents( $this->translation_dir() . '/other-plugin-' . self::LOCALE . '.mo' ) );

		$this->sut->update();
		$this->assertSame( 1, $this->download_calls );

		$this->response['data']['woocommerce-fraud-protection'][0]['last_modified'] = '2026-09-16 12:00:00+00:00';
		$this->sut->update();
		$this->assertSame( 1, $this->download_calls );
	}

	/**
	 * @testdox A valid l10n.php pack replaces the alternate runtime catalog and skips the same revision.
	 */
	public function test_l10n_php_update_replaces_stale_mo_and_skips_same_revision(): void {
		$this->write_file( $this->catalog_path( '.po' ), $this->po_contents( '2026-09-16 12:00:00+00:00' ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->download_source = $this->create_zip(
			array(
				self::PREFIX . '.po'       => $this->po_contents( self::REVISION ),
				self::PREFIX . '.l10n.php' => '<?php return array();',
			)
		);

		$this->sut->update();

		$this->assertSame( '<?php return array();', file_get_contents( $this->catalog_path( '.l10n.php' ) ) );
		$this->assertFileDoesNotExist( $this->catalog_path( '.mo' ) );

		$this->sut->update();
		$this->assertSame( 1, $this->download_calls );
	}

	/**
	 * @testdox A publish failure keeps prior catalogs and does not remove stale files.
	 */
	public function test_publish_failure_preserves_installed_files_and_skips_cleanup(): void {
		$new_json   = $this->catalog_path( '-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.json' );
		$stale_json = $this->catalog_path( '-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.json' );
		$this->write_file( $this->catalog_path( '.po' ), $this->po_contents( '2026-09-16 12:00:00+00:00' ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->write_file( $stale_json, 'stale json' );
		$this->assertTrue( wp_mkdir_p( $new_json ) );
		$this->download_source = $this->create_zip(
			array(
				self::PREFIX . '.po' => $this->po_contents( self::REVISION ),
				self::PREFIX . '.mo' => 'new runtime',
				self::PREFIX . '-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.json' => 'new json',
			)
		);
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test captures the expected rename warning.
		set_error_handler(
			static fn( int $severity, string $message ): bool => E_WARNING === $severity && str_contains( $message, 'rename(' )
		);

		try {
			$this->sut->update();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( 'old runtime', file_get_contents( $this->catalog_path( '.mo' ) ) );
		$this->assertStringContainsString( '2026-09-16', file_get_contents( $this->catalog_path( '.po' ) ) );
		$this->assertSame( 'stale json', file_get_contents( $stale_json ) );
	}

	/**
	 * @testdox Missing ZIP support fails safely and retains installed catalogs.
	 */
	public function test_missing_zip_support_preserves_installed_files(): void {
		$this->register_legacy_proxy_function_mocks( array( 'class_exists' => static fn() => false ) );
		$this->write_file( $this->catalog_path( '.po' ), $this->po_contents( '2026-09-16 12:00:00+00:00' ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->download_source = $this->valid_zip();

		$this->sut->update();

		$this->assertSame( 'old runtime', file_get_contents( $this->catalog_path( '.mo' ) ) );
		$this->assertStringContainsString( '2026-09-16', file_get_contents( $this->catalog_path( '.po' ) ) );
	}

	/**
	 * @testdox Lock contention exits before the metadata request.
	 */
	public function test_lock_contention_skips_request(): void {
		wp_mkdir_p( $this->translation_dir() );
		$handle = fopen( $this->translation_dir() . '/.woocommerce-fraud-protection-translation-91.lock', 'c' );
		$this->assertIsResource( $handle );
		$this->assertTrue( flock( $handle, LOCK_EX | LOCK_NB ) );

		$this->sut->update();

		flock( $handle, LOCK_UN );
		fclose( $handle );
		$this->assertSame( array(), $this->request );
	}

	/**
	 * @testdox Invalid endpoint data and package URLs are rejected.
	 * @dataProvider invalid_responses
	 *
	 * @param array<string, mixed> $response Endpoint response.
	 */
	public function test_invalid_responses_are_rejected( array $response ): void {
		$this->response        = $response;
		$this->download_source = $this->valid_zip();

		$this->sut->update();

		$this->assertSame( 0, $this->download_calls );
	}

	/**
	 * @testdox Endpoint transport and HTTP failures retain the installed runtime catalog.
	 * @dataProvider endpoint_failures
	 *
	 * @param mixed $response HTTP response or error.
	 */
	public function test_endpoint_failures_preserve_installed_runtime_catalog( $response ): void {
		$this->register_legacy_proxy_function_mocks( array( 'wp_remote_post' => static fn() => $response ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->download_source = $this->valid_zip();

		$this->sut->update();

		$this->assertSame( 0, $this->download_calls );
		$this->assertSame( 'old runtime', file_get_contents( $this->catalog_path( '.mo' ) ) );
	}

	/** @return array<string, array{mixed}> */
	public function endpoint_failures(): array {
		return array(
			'transport failure' => array( new \WP_Error( 'transport_failed' ) ),
			'non-200 response'  => array(
				array(
					'response' => array( 'code' => 503 ),
					'body'     => '',
				),
			),
		);
	}

	/** @return array<string, array{array<string, mixed>}> */
	public function invalid_responses(): array {
		$package         = $this->package_metadata();
		$missing_version = $package;
		unset( $missing_version['version'] );
		$invalid_version            = $package;
		$invalid_version['version'] = '';
		$untrusted                  = $package;
		$untrusted['package']       = 'https://example.com/package.zip';
		$credentialed               = $package;
		$credentialed['package']    = 'https://user@translate.wordpress.com/package.zip';
		$custom_port                = $package;
		$custom_port['package']     = 'https://translate.wordpress.com:443/package.zip';
		$fragment                   = $package;
		$fragment['package']        = 'https://translate.wordpress.com/package.zip#catalog';

		return array(
			'API failure'          => array( array( 'success' => false ) ),
			'wrong response owner' => array( array( 'data' => array( 'other-plugin' => array( $package ) ) ) ),
			'missing version'      => array( array( 'data' => array( 'woocommerce-fraud-protection' => array( $missing_version ) ) ) ),
			'invalid version'      => array( array( 'data' => array( 'woocommerce-fraud-protection' => array( $invalid_version ) ) ) ),
			'untrusted host'       => array( array( 'data' => array( 'woocommerce-fraud-protection' => array( $untrusted ) ) ) ),
			'URL credentials'      => array( array( 'data' => array( 'woocommerce-fraud-protection' => array( $credentialed ) ) ) ),
			'custom port'          => array( array( 'data' => array( 'woocommerce-fraud-protection' => array( $custom_port ) ) ) ),
			'URL fragment'         => array( array( 'data' => array( 'woocommerce-fraud-protection' => array( $fragment ) ) ) ),
		);
	}

	/**
	 * @testdox Unsafe or incomplete archives do not replace installed files.
	 * @dataProvider invalid_archives
	 *
	 * @param array<string, string> $files Archive files.
	 */
	public function test_invalid_archives_preserve_installed_files( array $files ): void {
		$this->write_file( $this->catalog_path( '.po' ), $this->po_contents( '2026-09-16 12:00:00+00:00' ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->download_source = $this->create_zip( $files );

		$this->sut->update();

		$this->assertSame( 'old runtime', file_get_contents( $this->catalog_path( '.mo' ) ) );
		$this->assertStringContainsString( '2026-09-16', file_get_contents( $this->catalog_path( '.po' ) ) );
	}

	/** @return array<string, array{array<string, string>}> */
	public function invalid_archives(): array {
		$po = $this->po_contents( self::REVISION );

		return array(
			'unrelated entry' => array(
				array(
					self::PREFIX . '.po' => $po,
					self::PREFIX . '.mo' => 'runtime',
					'readme.txt'         => 'readme',
				),
			),
			'traversal entry' => array(
				array(
					self::PREFIX . '.po'           => $po,
					self::PREFIX . '.mo'           => 'runtime',
					'../' . self::PREFIX . '.json' => 'escape',
				),
			),
			'duplicate name'  => array(
				array(
					'one/' . self::PREFIX . '.po' => $po,
					'two/' . self::PREFIX . '.po' => $po,
					self::PREFIX . '.mo'          => 'runtime',
				),
			),
			'missing PO'      => array( array( self::PREFIX . '.mo' => 'runtime' ) ),
			'missing runtime' => array(
				array(
					self::PREFIX . '.po' => $po,
					self::PREFIX . '-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.json' => 'json',
				),
			),
			'empty file'      => array(
				array(
					self::PREFIX . '.po' => $po,
					self::PREFIX . '.mo' => '',
				),
			),
		);
	}

	/**
	 * @testdox Download and extraction failures retain the installed catalogs.
	 * @dataProvider transfer_failures
	 *
	 * @param bool $download_fails Whether the download fails.
	 * @param bool $unzip_fails    Whether extraction fails.
	 */
	public function test_transfer_failures_retain_installed_catalogs( bool $download_fails, bool $unzip_fails ): void {
		$this->write_file( $this->catalog_path( '.po' ), $this->po_contents( '2026-09-16 12:00:00+00:00' ) );
		$this->write_file( $this->catalog_path( '.mo' ), 'old runtime' );
		$this->download_source = $this->valid_zip();
		$this->download_fails  = $download_fails;
		$this->unzip_fails     = $unzip_fails;

		$this->sut->update();

		$this->assertSame( 'old runtime', file_get_contents( $this->catalog_path( '.mo' ) ) );
	}

	/** @return array<string, array{bool, bool}> */
	public function transfer_failures(): array {
		return array(
			'download failure' => array( true, false ),
			'unzip failure'    => array( false, true ),
		);
	}

	/** Build a successful endpoint response. */
	private function package_response(): array {
		return array(
			'data' => array(
				'other-plugin'                 => array(),
				'woocommerce-fraud-protection' => array( $this->package_metadata() ),
			),
		);
	}

	/** Build package metadata. */
	private function package_metadata(): array {
		return array(
			'wp_locale'     => self::LOCALE,
			'version'       => 'v0_2_5',
			'package'       => 'https://translate.wordpress.com/packages/wcfp-pt_BR.zip',
			'last_modified' => self::REVISION,
		);
	}

	/** Create a valid translation ZIP. */
	private function valid_zip(): string {
		return $this->create_zip(
			array(
				self::PREFIX . '.po' => $this->po_contents( self::REVISION ),
				self::PREFIX . '.mo' => 'new runtime',
			)
		);
	}

	/**
	 * Create a controlled ZIP archive.
	 *
	 * @param array<string, string> $files Archive entries.
	 */
	private function create_zip( array $files ): string {
		$path = tempnam( sys_get_temp_dir(), 'wcfp-package-' );
		$this->assertIsString( $path );
		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $path, ZipArchive::OVERWRITE ) );
		foreach ( $files as $filename => $contents ) {
			$this->assertTrue( $zip->addFromString( $filename, $contents ) );
		}
		$zip->close();
		$this->temporary_paths[] = $path;

		return $path;
	}

	/**
	 * Create a PO header.
	 *
	 * @param string $revision Revision date.
	 */
	private function po_contents( string $revision ): string {
		return "msgid \"\"\nmsgstr \"\"\n\"PO-Revision-Date: {$revision}\\n\"\n";
	}

	/**
	 * Write a controlled file.
	 *
	 * @param string $path     File path.
	 * @param string $contents File contents.
	 */
	private function write_file( string $path, string $contents ): void {
		wp_mkdir_p( dirname( $path ) );
		$this->assertNotFalse( file_put_contents( $path, $contents ) );
	}

	/**
	 * Return a catalog path.
	 *
	 * @param string $suffix Filename suffix.
	 */
	private function catalog_path( string $suffix ): string {
		return $this->translation_dir() . '/' . self::PREFIX . $suffix;
	}

	/** Return the managed translation directory. */
	private function translation_dir(): string {
		return WP_LANG_DIR . '/mu-plugins';
	}

	/** Create the filesystem cleanup fake. */
	private function create_filesystem(): object {
		return new class() {
			/**
			 * Delete a test file or directory.
			 *
			 * @param string $path      Test path.
			 * @param bool   $recursive Whether to delete recursively.
			 */
			public function delete( string $path, bool $recursive = false ): bool {
				unset( $recursive );
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					$entries = scandir( $path );
					foreach ( false === $entries ? array() : $entries as $entry ) {
						if ( '.' !== $entry && '..' !== $entry ) {
							$this->delete( $path . '/' . $entry, true );
						}
					}
					return rmdir( $path );
				}
				return ! file_exists( $path ) || unlink( $path );
			}
		};
	}

	/**
	 * Remove a test path.
	 *
	 * @param string $path Test path.
	 */
	private function remove_path( string $path ): void {
		$GLOBALS['wp_filesystem']->delete( $path, true );
	}
}
