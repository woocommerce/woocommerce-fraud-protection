<?php
/**
 * ManagedTranslationUpdater class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\Proxies\LegacyProxy;
use ZipArchive;

defined( 'ABSPATH' ) || exit;
/**
 * Updates Fraud Protection translation catalogs on managed installations.
 */
class ManagedTranslationUpdater {
	private const CRON_HOOK       = 'woocommerce_fraud_protection_managed_translation_update';
	private const ENDPOINT        = 'https://translate.wordpress.com/api/translations-updates/woocommerce';
	private const PLUGIN_SLUG     = 'woocommerce-fraud-protection';
	private const REQUEST_TIMEOUT = 30;

	/**
	 * WordPress function proxy.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;
	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param LegacyProxy $legacy_proxy WordPress function proxy.
	 */
	final public function init( LegacyProxy $legacy_proxy ): void {
		$this->legacy_proxy = $legacy_proxy;
	}

	/**
	 * Register and schedule translation updates.
	 *
	 * @internal
	 */
	public function register(): void {
		add_action( self::CRON_HOOK, array( $this, 'update' ) );
		if ( ! $this->legacy_proxy->call_function( 'wp_next_scheduled', self::CRON_HOOK ) ) {
			$this->legacy_proxy->call_function( 'wp_schedule_event', time(), 'twicedaily', self::CRON_HOOK );
		}
	}

	/**
	 * Fetch and install available catalogs.
	 *
	 * @internal WP-Cron callback.
	 */
	public function update(): void {
		$lock = $this->acquire_lock();
		if ( null === $lock ) {
			return;
		}
		try {
			$locales = $this->get_locales();
			foreach ( empty( $locales ) ? array() : $this->request_packages( $locales ) as $package ) {
				if ( $this->is_newer_package( $package ) ) {
					$this->install_package( $package );
				}
			}
		} finally {
			$this->release_lock( $lock );
		}
	}

	/**
	 * Acquire the site translation update lock.
	 *
	 * @return resource|null Lock handle.
	 */
	private function acquire_lock() {
		$directory = $this->get_translation_dir();
		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return null;
		}
		$site_id = (int) $this->legacy_proxy->call_function( 'get_current_blog_id' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- flock requires a native handle.
		$handle = fopen( $directory . '/.woocommerce-fraud-protection-translation-' . $site_id . '.lock', 'c' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- The lock must not wait for another request.
		if ( false === $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( false !== $handle ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the unused lock handle.
				fclose( $handle );
			}
			return null;
		}
		return $handle;
	}

	/**
	 * Release the site translation update lock.
	 *
	 * @param resource $handle Lock handle.
	 */
	private function release_lock( $handle ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- Release the native lock.
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close the native lock handle.
		fclose( $handle );
	}

	/**
	 * Get valid unique site locales.
	 *
	 * @return string[] Valid unique locales.
	 */
	private function get_locales(): array {
		$current   = $this->legacy_proxy->call_function( 'get_locale' );
		$installed = $this->legacy_proxy->call_function( 'get_available_languages' );
		$locales   = is_array( $installed ) ? $installed : array();
		if ( is_string( $current ) ) {
			array_unshift( $locales, $current );
		}
		return array_values(
			array_unique(
				array_filter( $locales, static fn( $locale ): bool => is_string( $locale ) && 1 === preg_match( '/^[A-Za-z0-9_-]+$/D', $locale ) )
			)
		);
	}

	/**
	 * Request package metadata.
	 *
	 * @param string[] $locales Requested locales.
	 * @return array<int, array{wp_locale: string, version: string, package: string, last_modified: string}>
	 */
	private function request_packages( array $locales ): array {
		$body = wp_json_encode(
			array(
				'locales' => $locales,
				'plugins' => array( self::PLUGIN_SLUG => array( 'version' => 'latest' ) ),
			)
		);
		if ( ! is_string( $body ) ) {
			return array();
		}
		$response = $this->legacy_proxy->call_function(
			'wp_remote_post',
			self::ENDPOINT,
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => self::REQUEST_TIMEOUT,
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$data    = is_array( $decoded ) ? ( $decoded['data'] ?? null ) : null;
		if ( ! is_array( $decoded ) || false === ( $decoded['success'] ?? true ) || ! is_array( $data ) || ! is_array( $data[ self::PLUGIN_SLUG ] ?? null ) ) {
			return array();
		}
		$packages = array();
		foreach ( $data[ self::PLUGIN_SLUG ] as $package ) {
			if ( ! is_array( $package ) ) {
				return array();
			}
			$locale        = $package['wp_locale'] ?? null;
			$version       = $package['version'] ?? null;
			$url           = $package['package'] ?? null;
			$last_modified = $package['last_modified'] ?? null;
			if ( ! is_string( $locale ) || ! in_array( $locale, $locales, true ) || isset( $packages[ $locale ] )
				|| ! is_string( $version ) || '' === $version || ! is_string( $url ) || ! $this->is_trusted_url( $url )
				|| ! is_string( $last_modified ) || false === strtotime( $last_modified ) ) {
				return array();
			}
			/**
			 * Validated package metadata.
			 *
			 * @var array{wp_locale: string, version: string, package: string, last_modified: string} $package
			 */
			$packages[ $locale ] = $package;
		}
		return array_values( $packages );
	}

	/**
	 * Check that a package URL uses the approved origin.
	 *
	 * @param string $url Package URL.
	 */
	private function is_trusted_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === strtolower( $parts['scheme'] ?? '' )
			&& 'translate.wordpress.com' === strtolower( $parts['host'] ?? '' )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['port'] )
			&& ! isset( $parts['fragment'] )
			&& ! empty( $parts['path'] );
	}

	/**
	 * Check the installed revision and runtime catalog.
	 *
	 * @param array{wp_locale: string, version: string, package: string, last_modified: string} $package Package metadata.
	 */
	private function is_newer_package( array $package ): bool {
		$prefix = $this->get_translation_dir() . '/' . self::PLUGIN_SLUG . '-' . $package['wp_locale'];
		if ( ! $this->is_nonempty_file( $prefix . '.mo' ) && ! $this->is_nonempty_file( $prefix . '.l10n.php' ) ) {
			return true;
		}
		$revision = $this->read_po_revision( $prefix . '.po' );
		return null === $revision || strtotime( $package['last_modified'] ) > $revision;
	}

	/**
	 * Read a revision timestamp from a PO file.
	 *
	 * @param string $path PO file path.
	 */
	private function read_po_revision( string $path ): ?int {
		if ( ! $this->is_nonempty_file( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read a bounded local catalog header.
		$header = file_get_contents( $path, false, null, 0, 8192 );
		if ( ! is_string( $header ) || 1 !== preg_match( '/^"PO-Revision-Date:\s*(.+?)\\\\n"\s*$/m', $header, $matches ) ) {
			return null;
		}
		$timestamp = strtotime( $matches[1] );
		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Download, validate, and install a package.
	 *
	 * @param array{wp_locale: string, version: string, package: string, last_modified: string} $package Package metadata.
	 */
	private function install_package( array $package ): bool {
		if ( ! $this->load_file_functions() ) {
			return false;
		}
		$zip_path = $this->legacy_proxy->call_function( 'download_url', $package['package'], self::REQUEST_TIMEOUT );
		if ( is_wp_error( $zip_path ) || ! is_string( $zip_path ) || ! is_readable( $zip_path ) ) {
			return false;
		}
		$extract_dir = $this->create_temp_dir();
		try {
			if ( null === $extract_dir || ! $this->validate_archive( $zip_path, $package['wp_locale'] )
				|| true !== $this->legacy_proxy->call_function( 'unzip_file', $zip_path, $extract_dir ) ) {
				return false;
			}
			$files = $this->collect_files( $extract_dir, $package['wp_locale'] );
			return null !== $files && $this->install_files( $files, $package['wp_locale'] );
		} finally {
			wp_delete_file( $zip_path );
			if ( null !== $extract_dir ) {
				$this->remove_directory( $extract_dir );
			}
		}
	}

	/** Load WordPress file helpers. */
	private function load_file_functions(): bool {
		global $wp_filesystem;

		if ( ! function_exists( 'download_url' ) || ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return (bool) $this->legacy_proxy->call_function( 'WP_Filesystem' ) && is_object( $wp_filesystem );
	}

	/** Create a temporary extraction directory. */
	private function create_temp_dir(): ?string {
		$path = wp_tempnam( 'wcfp-translations' );
		if ( ! is_string( $path ) ) {
			return null;
		}
		wp_delete_file( $path );
		return wp_mkdir_p( $path ) ? $path : null;
	}

	/**
	 * Reject unsafe or unexpected archive entries before extraction.
	 *
	 * @param string $path   Archive path.
	 * @param string $locale Requested locale.
	 */
	private function validate_archive( string $path, string $locale ): bool {
		if ( ! $this->legacy_proxy->call_function( 'class_exists', ZipArchive::class ) ) {
			return false;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return false;
		}
		$files = array();
		try {
			for ( $index = 0; $index < $zip->numFiles; ++$index ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Property belongs to ZipArchive.
				$stat = $zip->statIndex( $index );
				if ( false === $stat || ! is_string( $stat['name'] ?? null ) ) {
					return false;
				}
				$name       = str_replace( '\\', '/', $stat['name'] );
				$components = explode( '/', trim( $name, '/' ) );
				$attributes = 0;
				$zip->getExternalAttributesIndex( $index, $operating_system, $attributes );
				$is_link = 0120000 === ( ( $attributes >> 16 ) & 0170000 );
				if ( '' === $name || '/' === $name[0] || in_array( '..', $components, true ) || $is_link ) {
					return false;
				}
				if ( str_ends_with( $name, '/' ) ) {
					continue;
				}
				$basename = basename( $name );
				if ( ! $this->is_expected_filename( $basename, $locale ) || isset( $files[ $basename ] ) || 0 === (int) ( $stat['size'] ?? 0 ) ) {
					return false;
				}
				$files[ $basename ] = true;
			}
		} finally {
			$zip->close();
		}
		$prefix = self::PLUGIN_SLUG . '-' . $locale;
		return isset( $files[ $prefix . '.po' ] ) && ( isset( $files[ $prefix . '.mo' ] ) || isset( $files[ $prefix . '.l10n.php' ] ) );
	}

	/**
	 * Collect validated extracted files.
	 *
	 * @param string $directory Extraction directory.
	 * @param string $locale    Requested locale.
	 *
	 * @return array<string, string>|null Filename-to-path map.
	 */
	private function collect_files( string $directory, string $locale ): ?array {
		$root = realpath( $directory );
		if ( false === $root ) {
			return null;
		}
		$files = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$path     = $file->getPathname();
			$real     = realpath( $path );
			$basename = $file->getBasename();
			if ( $file->isLink() || ! $file->isFile() || false === $real || 0 !== strpos( $real, trailingslashit( $root ) )
				|| ! $this->is_expected_filename( $basename, $locale ) || isset( $files[ $basename ] ) || 0 === (int) filesize( $path ) ) {
				return null;
			}
			$files[ $basename ] = $path;
		}
		$prefix = self::PLUGIN_SLUG . '-' . $locale;
		return isset( $files[ $prefix . '.po' ] ) && ( isset( $files[ $prefix . '.mo' ] ) || isset( $files[ $prefix . '.l10n.php' ] ) ) ? $files : null;
	}

	/**
	 * Check an expected catalog filename.
	 *
	 * @param string $filename Catalog filename.
	 * @param string $locale   Requested locale.
	 */
	private function is_expected_filename( string $filename, string $locale ): bool {
		$prefix = preg_quote( self::PLUGIN_SLUG . '-' . $locale, '/' );
		return 1 === preg_match( '/^' . $prefix . '\.(?:po|mo|l10n\.php)$/D', $filename )
			|| 1 === preg_match( '/^' . $prefix . '-[a-f0-9]{32}\.json$/D', $filename );
	}

	/**
	 * Stage and replace validated catalogs.
	 *
	 * @param array<string, string> $files  Filename-to-path map.
	 * @param string                $locale Requested locale.
	 */
	private function install_files( array $files, string $locale ): bool {
		$directory = $this->get_translation_dir();
		$staged    = array();
		foreach ( $files as $filename => $source ) {
			$stage = wp_tempnam( $filename, $directory );
			if ( ! is_string( $stage ) ) {
				$this->delete_files( $staged );
				return false;
			}
			$staged[ $filename ] = $stage;
			if ( ! copy( $source, $stage ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
				$this->delete_files( $staged );
				return false;
			}
		}
		$ordered = array_keys( $staged );
		usort( $ordered, array( $this, 'compare_install_order' ) );
		foreach ( $ordered as $filename ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename publishes a complete file.
			if ( ! rename( $staged[ $filename ], $directory . '/' . $filename ) ) {
				$this->delete_files( $staged );
				return false;
			}
			unset( $staged[ $filename ] );
		}
		foreach ( $this->find_locale_files( $locale ) as $filename => $path ) {
			if ( ! isset( $files[ $filename ] ) ) {
				wp_delete_file_from_directory( $path, $directory );
			}
		}
		return true;
	}

	/**
	 * Put PO files last so an interrupted install retries.
	 *
	 * @param string $left  Left filename.
	 * @param string $right Right filename.
	 */
	private function compare_install_order( string $left, string $right ): int {
		$priority = static fn( string $filename ): int => str_ends_with( $filename, '.po' ) ? 2 : ( str_ends_with( $filename, '.json' ) ? 0 : 1 );
		return $priority( $left ) <=> $priority( $right );
	}

	/**
	 * Find installed files for one locale.
	 *
	 * @param string $locale Requested locale.
	 * @return array<string, string> Installed files for one locale.
	 */
	private function find_locale_files( string $locale ): array {
		$files = array();
		foreach ( new \DirectoryIterator( $this->get_translation_dir() ) as $file ) {
			if ( $file->isFile() && ! $file->isLink() && $this->is_expected_filename( $file->getBasename(), $locale ) ) {
				$files[ $file->getBasename() ] = $file->getPathname();
			}
		}
		return $files;
	}

	/**
	 * Delete temporary files.
	 *
	 * @param string[] $files Temporary file paths.
	 */
	private function delete_files( array $files ): void {
		foreach ( $files as $path ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Remove a temporary directory.
	 *
	 * @param string $directory Temporary directory.
	 */
	private function remove_directory( string $directory ): void {
		global $wp_filesystem;
		if ( is_object( $wp_filesystem ) && is_callable( array( $wp_filesystem, 'delete' ) ) ) {
			call_user_func( array( $wp_filesystem, 'delete' ), $directory, true );
		}
	}

	/**
	 * Check for a nonempty file.
	 *
	 * @param string $path File path.
	 */
	private function is_nonempty_file( string $path ): bool {
		return is_file( $path ) && 0 < (int) filesize( $path );
	}

	/** Get the managed translation directory. */
	private function get_translation_dir(): string {
		return untrailingslashit( WP_LANG_DIR . '/mu-plugins' );
	}
}
